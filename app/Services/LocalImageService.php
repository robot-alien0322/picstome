<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

/**
 * Eugene fork: resize photos on this server instead of an external image CDN (wsrv.nl had
 * to pull every full-size original over the home internet connection, so the viewer sat on
 * a blurry pulsing preview for seconds). Square "fit inside" boxes only, cached on disk.
 */
class LocalImageService
{
    /** Box sizes the app asks for: small grid, grid/viewer, large viewer, zoom. */
    public static function boxes(): array
    {
        $resize = (int) config('picstome.photo_resize', 2048);

        return array_values(array_unique([
            (int) config('picstome.photo_small_thumb_resize', 500),
            (int) config('picstome.photo_thumb_resize', 1000),
            $resize,
            $resize * 2,
        ]));
    }

    /** Can this CDN parameter set be served locally? (plain w=h box, no crops) */
    public static function handles(array $params): bool
    {
        $extra = array_diff(array_keys($params), ['w', 'h', 'q', 'output', 'v']);

        return $extra === []
            && isset($params['w'], $params['h'])
            && (int) $params['w'] === (int) $params['h']
            && in_array((int) $params['w'], self::boxes(), true);
    }

    public static function url(string $path, int $box, int $version = 0): string
    {
        return route('fork.image', ['path' => $path]).'?'.http_build_query(array_filter(['s' => $box, 'v' => $version ?: null]));
    }

    /** Local cached webp for this stored photo path at this box size (rendered on first use). */
    public function cachedPath(string $path, int $box, string $disk = 's3'): string
    {
        $dir = storage_path('app/img-cache');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $cached = $this->cacheFile($path, $box, $disk);

        if (is_file($cached)) {
            return $cached;
        }

        // Limited number of renders at once (RenderSlots): bursts used to decode several 33 MP
        // originals in parallel and starve the VM. Waiters usually find the file already made.
        return \App\Support\RenderSlots::run(
            fn () => is_file($cached) ? $cached : $this->render($path, $box, $disk, $dir, $cached)
        );
    }

    private function cacheFile(string $path, int $box, string $disk): string
    {
        return storage_path('app/img-cache').'/'.sha1($disk.'|'.$path).'-'.$box.'.webp';
    }

    private function render(string $path, int $box, string $disk, string $dir, string $cached): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
        $source = $dir.'/src-'.Str::ulid().'.'.$extension;
        $partial = $dir.'/part-'.Str::ulid().'.webp';

        $in = Storage::disk($disk)->readStream($path);
        abort_unless(is_resource($in), 404);

        $out = fopen($source, 'w');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        try {
            \App\Support\FastResize::toFile($source, $partial, $box, 'webp', 90);
            rename($partial, $cached);
        } finally {
            @unlink($source);
            @unlink($partial);
        }

        return $cached;
    }

    /** Pre-render the sizes the grid and viewer use, so the first click is instant. */
    public function warm(Photo|\App\Models\PhotoVersion $photo): void
    {
        if (! $photo->isImage() || ! $photo->path) {
            return;
        }

        $disk = $photo->diskOrDefault();
        $boxes = [(int) config('picstome.photo_resize', 2048), (int) config('picstome.photo_thumb_resize', 1000)];

        // Eugene fork: both sizes from one download + one decode of the original (was one each)
        \App\Support\RenderSlots::run(function () use ($photo, $disk, $boxes) {
            $outputs = [];
            foreach ($boxes as $box) {
                $cached = $this->cacheFile($photo->path, $box, $disk);
                if (! is_file($cached)) {
                    $outputs[] = ['box' => $box, 'format' => 'webp', 'quality' => 90, 'cached' => $cached,
                        'dest' => dirname($cached).'/part-'.Str::ulid().'.webp'];
                }
            }

            if (! $outputs) {
                return;
            }

            $dir = dirname($outputs[0]['cached']);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $extension = strtolower(pathinfo($photo->path, PATHINFO_EXTENSION)) ?: 'jpg';
            $source = $dir.'/src-'.Str::ulid().'.'.$extension;

            $in = Storage::disk($disk)->readStream($photo->path);
            abort_unless(is_resource($in), 404);
            $out = fopen($source, 'w');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            try {
                \App\Support\FastResize::multi($source, $outputs);
                foreach ($outputs as $o) {
                    rename($o['dest'], $o['cached']);
                }
            } finally {
                @unlink($source);
                foreach ($outputs as $o) {
                    @unlink($o['dest']);
                }
            }
        });
    }
}

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

        $cached = $dir.'/'.sha1($disk.'|'.$path).'-'.$box.'.webp';

        if (is_file($cached)) {
            return $cached;
        }

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

        foreach ([(int) config('picstome.photo_thumb_resize', 1000), (int) config('picstome.photo_resize', 2048)] as $box) {
            $this->cachedPath($photo->path, $box, $photo->diskOrDefault());
        }
    }
}

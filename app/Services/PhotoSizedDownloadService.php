<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Eugene fork: Zenfolio-style download sizes for single photos (long edge in px).
 * A resized copy is made on the first request and cached on local disk for a week.
 */
class PhotoSizedDownloadService
{
    public const SIZES = ['high' => 3264, 'medium' => 2560, 'low' => 1280];

    private const LABELS = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    private const CACHE_DAYS = 7;

    public static function label(string $size): string
    {
        return __(self::LABELS[$size] ?? ucfirst($size)).' ('.self::SIZES[$size].' px)';
    }

    /**
     * Sizes worth offering for this photo: only those smaller than its long edge.
     *
     * @return array<string, int>
     */
    public static function sizesFor(Photo $photo): array
    {
        if (! $photo->isImage() || ! $photo->path) {
            return [];
        }

        $long = max((int) $photo->width, (int) $photo->height);

        return array_filter(self::SIZES, fn (int $px) => $long === 0 || $px < $long);
    }

    public function download(Photo $photo, string $size): BinaryFileResponse
    {
        abort_unless(isset(self::SIZES[$size]), 404);

        $cached = $this->resizedPath($photo, $size, force: true);
        abort_unless($cached !== null, 404);

        $name = pathinfo($photo->downloadFileName(), PATHINFO_FILENAME).'-'.$size.'.jpg';

        return response()->download($cached, $name, ['Content-Type' => 'image/jpeg']);
    }

    /**
     * Local path of the resized copy (made and cached on first use). Null when the
     * photo is not an image or is already no bigger than the size, unless $force.
     */
    public function resizedPath(Photo $photo, string $size, bool $force = false): ?string
    {
        if (! isset(self::SIZES[$size]) || ! $photo->isImage() || ! $photo->path) {
            return null;
        }

        $px = self::SIZES[$size];

        if (! $force && ! isset(self::sizesFor($photo)[$size])) {
            return null;
        }

        $dir = storage_path('app/download-sizes');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $cached = $dir.'/'.$photo->id.'-'.$size.'-'.substr(sha1($photo->path.'|'.$photo->updated_at), 0, 12).'.jpg';

        if (! is_file($cached)) {
            $this->render($photo, $px, $cached);
            $this->prune($dir);
        }

        return $cached;
    }

    protected function render(Photo $photo, int $px, string $cached): void
    {
        $extension = strtolower(pathinfo($photo->path, PATHINFO_EXTENSION)) ?: 'jpg';
        $source = dirname($cached).'/src-'.Str::ulid().'.'.$extension;
        $partial = dirname($cached).'/part-'.Str::ulid().'.jpg';

        $in = Storage::disk($photo->diskOrDefault())->readStream($photo->path);
        abort_unless(is_resource($in), 404);

        $out = fopen($source, 'w');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        try {
            \App\Support\FastResize::toFile($source, $partial, $px, 'jpeg', 90);
            rename($partial, $cached); // atomic: a parallel request never sees a half-written file
        } finally {
            @unlink($source);
            @unlink($partial);
        }
    }

    /** At most once an hour, drop cached copies older than a week. */
    protected function prune(string $dir): void
    {
        $marker = $dir.'/.pruned';

        if (is_file($marker) && filemtime($marker) > time() - 3600) {
            return;
        }

        @touch($marker);

        foreach (glob($dir.'/*.jpg') ?: [] as $file) {
            if (filemtime($file) < time() - self::CACHE_DAYS * 86400) {
                @unlink($file);
            }
        }
    }
}

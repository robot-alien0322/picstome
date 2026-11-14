<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Eugene fork: one smaller download next to the original - "Web & Social", 2048 px on the long
 * edge (Facebook's display size; Instagram downsizes it further). Pre-rendered after upload.
 * (Was High 3264 / Medium 2560 / Low 1280 until 2026-11-13: too many choices, 3x the work.)
 */
class PhotoSizedDownloadService
{
    public const SIZES = ['web' => 2048];

    private const LABELS = ['web' => 'Web & Social'];

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
            \App\Support\RenderSlots::run(fn () => \App\Support\FastResize::toFile($source, $partial, $px, 'jpeg', 90));
            rename($partial, $cached); // atomic: a parallel request never sees a half-written file
        } finally {
            @unlink($source);
            @unlink($partial);
        }
    }

    /**
     * At most once a day, drop copies of photos that were deleted or re-processed (the file
     * name carries the photo id and a hash of its path + updated_at). Current copies stay,
     * since they are pre-rendered after upload so sized downloads never wait.
     */
    protected function prune(string $dir): void
    {
        $marker = $dir.'/.pruned';

        if (is_file($marker) && filemtime($marker) > time() - 86400) {
            return;
        }

        @touch($marker);

        $current = [];
        foreach (Photo::query()->whereNotNull('path')->get(['id', 'path', 'updated_at']) as $photo) {
            $current[$photo->id] = substr(sha1($photo->path.'|'.$photo->updated_at), 0, 12);
        }

        foreach (glob($dir.'/*.jpg') ?: [] as $file) {
            if (preg_match('/^(\d+)-[a-z]+-([0-9a-f]{12})\.jpg$/', basename($file), $m)
                && ($current[(int) $m[1]] ?? null) !== $m[2]
                && filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    /** Make High/Medium/Low for one photo (skips sizes the photo is already smaller than). */
    public function renderAll(Photo $photo): void
    {
        foreach (array_keys(self::sizesFor($photo)) as $size) {
            $this->resizedPath($photo, $size);
        }
    }
}

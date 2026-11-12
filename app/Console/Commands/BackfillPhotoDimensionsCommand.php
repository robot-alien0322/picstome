<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Support\PhotoDimensions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Eugene fork: store width/height for photos that don't have them, reading only the
 * first 2 MB of each file (enough for the JPEG header and EXIF).
 */
class BackfillPhotoDimensionsCommand extends Command
{
    protected $signature = 'photos:backfill-dimensions {--gallery= : Only this gallery id} {--force : Recompute photos that already have dimensions}';

    protected $description = 'Store photo width/height so justified galleries lay out before images load';

    public function handle(): int
    {
        $query = Photo::query()->whereNotNull('path');

        if ($gallery = $this->option('gallery')) {
            $query->where('gallery_id', $gallery);
        }

        if (! $this->option('force')) {
            $query->where(fn ($q) => $q->whereNull('width')->orWhereNull('height')->orWhere('width', 0)->orWhere('height', 0));
        }

        $updated = 0;
        $skipped = 0;

        $query->chunkById(100, function ($photos) use (&$updated, &$skipped) {
            foreach ($photos as $photo) {
                $dims = $this->dimensionsFor($photo);

                if ($dims) {
                    $photo->forceFill(['width' => $dims[0], 'height' => $dims[1]])->saveQuietly();
                    $updated++;
                } else {
                    $skipped++;
                }
            }
        });

        $this->info("Updated {$updated} photos, skipped {$skipped} (videos, PDFs or unreadable).");

        return self::SUCCESS;
    }

    protected function dimensionsFor(Photo $photo): ?array
    {
        try {
            $stream = Storage::disk($photo->diskOrDefault())->readStream($photo->path);

            if (! is_resource($stream)) {
                return null;
            }

            $head = stream_get_contents($stream, 2 * 1024 * 1024);
            fclose($stream);

            return $head ? PhotoDimensions::fromBytes($head) : null;
        } catch (Throwable) {
            return null;
        }
    }
}

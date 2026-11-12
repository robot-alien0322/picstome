<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\LocalImageService;
use Illuminate\Console\Command;
use Throwable;

/** Eugene fork: pre-render local grid/viewer images for existing photos. */
class WarmLocalImagesCommand extends Command
{
    protected $signature = 'photos:warm-local-images {--gallery= : Only this gallery id}';

    protected $description = 'Pre-render the local 1000px/2048px images used by the grid and photo viewer';

    public function handle(LocalImageService $images): int
    {
        $query = Photo::query()->whereNotNull('path');

        if ($gallery = $this->option('gallery')) {
            $query->where('gallery_id', $gallery);
        }

        $done = 0;
        $failed = 0;

        $query->lazyById(100)->each(function (Photo $photo) use ($images, &$done, &$failed) {
            try {
                $images->warm($photo);
                $done++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("photo {$photo->id}: {$e->getMessage()}");
            }

            if (($done + $failed) % 50 === 0) {
                $this->line(($done + $failed).' photos...');
            }
        });

        $this->info("Warmed {$done} photos, {$failed} failed.");

        return self::SUCCESS;
    }
}

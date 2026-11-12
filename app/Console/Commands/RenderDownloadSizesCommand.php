<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\PhotoSizedDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Concurrency;

/** Eugene fork: pre-render High/Medium/Low download copies for existing photos. */
class RenderDownloadSizesCommand extends Command
{
    protected $signature = 'photos:render-download-sizes {--gallery= : Only this gallery id} {--workers=3 : Parallel processes}';

    protected $description = 'Pre-render the High/Medium/Low download copies for existing photos';

    public function handle(): int
    {
        $query = Photo::query()->whereNotNull('path')->orderBy('id');

        if ($gallery = $this->option('gallery')) {
            $query->where('gallery_id', $gallery);
        }

        $ids = $query->pluck('id')->all();
        $workers = max(1, (int) $this->option('workers'));
        $done = 0;

        foreach (array_chunk($ids, $workers * 8) as $batch) {
            $tasks = [];

            foreach (array_chunk($batch, (int) ceil(count($batch) / $workers)) as $part) {
                $tasks[] = static function () use ($part) {
                    foreach (Photo::query()->whereIn('id', $part)->get() as $photo) {
                        try {
                            app(PhotoSizedDownloadService::class)->renderAll($photo);
                        } catch (\Throwable) {
                            // a later download or ZIP retries
                        }
                    }

                    return count($part);
                };
            }

            Concurrency::run($tasks);
            $done += count($batch);
            $this->line("{$done}/".count($ids).' photos');
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}

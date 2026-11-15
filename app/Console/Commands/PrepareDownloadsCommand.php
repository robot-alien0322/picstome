<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Services\PrepareDownloadsService;
use Illuminate\Console\Command;

/** Eugene fork: build client ZIPs ahead of time (see PrepareDownloadsService). */
class PrepareDownloadsCommand extends Command
{
    protected $signature = 'galleries:prepare-downloads
        {gallery?* : Gallery ids (not needed with --auto)}
        {--auto : Shared, downloadable galleries whose uploads have been quiet for a while}';

    protected $description = 'Eugene fork: prepare the client ZIPs (full size + Web & Social) ahead of time';

    public function handle(PrepareDownloadsService $service): int
    {
        if ($this->option('auto')) {
            if (! config('fork.prepare_downloads.auto')) {
                $this->info('Automatic ZIP preparation is turned off (PICSTOME_AUTO_PREPARE_DOWNLOADS).');

                return self::SUCCESS;
            }

            $galleries = Gallery::where('is_shared', true)->where('is_share_downloadable', true)->get()
                ->filter(fn (Gallery $g) => $service->autoEligible($g));
            $retryFailed = false;
        } else {
            $ids = $this->argument('gallery');
            if ($ids === []) {
                $this->error('Give gallery ids or use --auto.');

                return self::FAILURE;
            }
            $galleries = Gallery::whereIn('id', $ids)->get();
            $retryFailed = true;
        }

        foreach ($galleries as $gallery) {
            $started = $service->prepare($gallery, null, $retryFailed);
            if ($started > 0 || ! $this->option('auto')) {
                $this->line("#{$gallery->id} {$gallery->name}: {$started} ZIP build(s) started");
            }
        }

        return self::SUCCESS;
    }
}

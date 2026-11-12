<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\PhotoSizedDownloadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Eugene fork: pre-render the High/Medium/Low download copies right after a photo is
 * processed, so sized single downloads and sized ZIPs never wait on resizing.
 */
class RenderDownloadSizes implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $photoId) {}

    public function handle(PhotoSizedDownloadService $sizes): void
    {
        $photo = Photo::query()->find($this->photoId);

        if ($photo && $photo->isImage() && $photo->path) {
            $sizes->renderAll($photo);
        }
    }
}

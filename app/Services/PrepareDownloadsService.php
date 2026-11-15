<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\GalleryActivity;
use App\Models\GalleryArchive;
use App\Models\User;

/**
 * Eugene fork: prepare the client ZIPs (full size + Web & Social) ahead of time, so a client's
 * "Download all" is instant instead of "preparing...". Used by the "Prepare downloads" button and by
 * galleries:prepare-downloads --auto (shared galleries whose uploads have gone quiet).
 */
class PrepareDownloadsService
{
    public function __construct(private GalleryArchiveService $archives) {}

    /** @return list<string> */
    public function variants(): array
    {
        return config('fork.prepare_downloads.variants', ['all', 'all@web']);
    }

    public function label(string $variant): string
    {
        [, $size] = GalleryArchiveService::splitVariant($variant);

        return $size ? PhotoSizedDownloadService::label($size) : __('Original (full resolution)');
    }

    /** The archive matching the gallery's current content for this variant, if any. */
    public function current(Gallery $gallery, string $variant): ?GalleryArchive
    {
        return GalleryArchive::query()
            ->where('gallery_id', $gallery->id)
            ->where('variant', $variant)
            ->where('fingerprint', $this->archives->fingerprint($gallery, $variant))
            ->latest('id')
            ->first();
    }

    /** @return list<array{variant: string, label: string, status: string, size: ?int, processed: int, total: int, expires_at: mixed}> */
    public function status(Gallery $gallery): array
    {
        return array_map(function (string $variant) use ($gallery) {
            $archive = $this->current($gallery, $variant);

            $status = match (true) {
                $archive === null, $archive->isExpired() => 'missing',
                $archive->isReady() => 'ready',
                $archive->isPending(), $archive->isBuilding() => 'preparing',
                default => 'failed',
            };

            return [
                'variant' => $variant,
                'label' => $this->label($variant),
                'status' => $status,
                'size' => $archive?->size_bytes,
                'processed' => (int) ($archive?->photos_processed ?? 0),
                'total' => (int) ($archive?->photos_total ?? 0),
                'expires_at' => $archive?->expires_at,
            ];
        }, $this->variants());
    }

    /**
     * Start whatever is missing. A build that failed recently is only retried when asked by hand.
     * Returns how many builds were started.
     */
    public function prepare(Gallery $gallery, ?User $user = null, bool $retryFailed = true): int
    {
        if (! $this->archives->enabled()) {
            return 0;
        }

        $started = 0;

        foreach ($this->variants() as $variant) {
            $archive = $this->current($gallery, $variant);

            if ($archive && ! $archive->isExpired() && ($archive->isReady() || $archive->isPending() || $archive->isBuilding())) {
                continue;
            }

            if ($archive && $archive->isFailed() && ! $retryFailed && $archive->updated_at?->gt(now()->subHours(6))) {
                continue;
            }

            $this->archives->findOrCreate($gallery, $variant, $user);
            $started++;
        }

        return $started;
    }

    /** Shared + downloadable, recently active, and nothing uploaded or processing for a while. */
    public function autoEligible(Gallery $gallery): bool
    {
        $cfg = config('fork.prepare_downloads');
        $cutoff = now()->subDays((int) $cfg['active_days']);

        if (! $gallery->is_shared || ! $gallery->is_share_downloadable) {
            return false;
        }

        if (! $gallery->photos()->where('status', 'processed')->exists()) {
            return false;
        }

        $busy = $gallery->photos()
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'processing'])
                ->orWhere('uploaded_at', '>=', now()->subMinutes((int) $cfg['quiet_minutes'])))
            ->exists();

        if ($busy) {
            return false;
        }

        return $gallery->created_at >= $cutoff
            || GalleryActivity::where('gallery_id', $gallery->id)->where('created_at', '>=', $cutoff)->exists();
    }
}

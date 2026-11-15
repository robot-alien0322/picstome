<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Models\GalleryActivity;
use App\Models\Photo;
use App\Models\PhotoVersion;
use App\Services\GalleryArchiveService;
use App\Services\StorageUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Eugene fork: R2 tiering.
 *
 * Active galleries get a copy of their photos (and current ZIPs) on Cloudflare R2, so full-size and
 * ZIP downloads are served from R2 instead of the home upload. Garage ("home") always keeps every
 * photo, which makes it the backup, keeps display URLs (thumbnails, /media) working, and makes moving
 * a gallery home cheap: switch it back and delete the R2 copy.
 *
 * Only processed image files move; videos/PDFs and photos still processing stay home. Grid thumbnails
 * (thumb_path) never go to R2: they are only ever shown from the home copy.
 */
class TierGalleriesCommand extends Command
{
    protected $signature = 'galleries:tier
        {gallery?* : Gallery ids (not needed with --auto)}
        {--to= : r2 or home}
        {--auto : Policy: active galleries to R2, galleries quiet for the configured days back home}
        {--dry-run : Only show what would move}';

    protected $description = 'Eugene fork: move galleries\' downloads between Cloudflare R2 and home (Garage)';

    private string $r2;

    private string $home;

    public function handle(GalleryArchiveService $archives): int
    {
        $cfg = config('fork.r2_tiering');
        $this->r2 = $cfg['disk'];
        $this->home = $cfg['home_disk'];

        if ($this->option('auto')) {
            if (! $cfg['enabled']) {
                $this->info('R2 tiering is turned off (PICSTOME_R2_TIERING).');

                return self::SUCCESS;
            }
            [$up, $down] = $this->plan((int) $cfg['active_days'], (int) $cfg['quiet_minutes']);
        } else {
            $to = $this->option('to');
            $ids = $this->argument('gallery');
            if (! in_array($to, ['r2', 'home'], true) || $ids === []) {
                $this->error('Give gallery ids and --to=r2 or --to=home (or use --auto).');

                return self::FAILURE;
            }
            $galleries = Gallery::whereIn('id', $ids)->get();
            [$up, $down] = $to === 'r2' ? [$galleries, collect()] : [collect(), $galleries];
        }

        if ($up->isEmpty() && $down->isEmpty()) {
            $this->info('Nothing to move.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($up as $gallery) {
            $failed += $this->move($gallery, $this->home, $this->r2, $archives);
        }
        foreach ($down as $gallery) {
            $failed += $this->move($gallery, $this->r2, $this->home, $archives);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: Collection<int, Gallery>, 1: Collection<int, Gallery>} */
    private function plan(int $activeDays, int $quietMinutes): array
    {
        $cutoff = now()->subDays($activeDays);
        $isActive = fn (Gallery $g) => $g->created_at >= $cutoff
            || GalleryActivity::where('gallery_id', $g->id)->where('created_at', '>=', $cutoff)->exists();
        $isQuiet = fn (Gallery $g) => ! $g->photos()
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'processing'])
                ->orWhere('uploaded_at', '>=', now()->subMinutes($quietMinutes)))
            ->exists();

        $withDisk = fn (string $disk) => Gallery::whereHas('photos', fn ($q) => $q->where('disk', $disk))->get();

        $up = $withDisk($this->home)->filter(fn (Gallery $g) => $isActive($g) && $isQuiet($g))->values();
        $down = $withDisk($this->r2)->reject($isActive)->values();

        return [$up, $down];
    }

    /** Returns the number of files that failed. */
    private function move(Gallery $gallery, string $from, string $to, GalleryArchiveService $archives): int
    {
        $label = "#{$gallery->id} {$gallery->name}";
        $toR2 = $to === $this->r2;

        $photos = $gallery->photos()->where('disk', $from)->where('status', 'processed')->get()
            ->filter(fn (Photo $p) => $p->isImage())->values();
        $versions = PhotoVersion::whereIn('photo_id', $gallery->photos()->select('id'))->where('disk', $from)->get()
            ->filter(fn (PhotoVersion $v) => $v->isImage())->values();
        $bytes = $photos->sum('stored_size') + $versions->sum('size');

        $this->line(sprintf('%s: %d photo(s), %d version(s), %.2f GB -> %s',
            $label, $photos->count(), $versions->count(), $bytes / 1e9, $toR2 ? 'R2' : 'home'));

        if ($this->option('dry-run') || ($photos->isEmpty() && $versions->isEmpty())) {
            return 0;
        }

        // ZIPs that are current now; they stay valid after the move (same content), so their
        // fingerprint is refreshed instead of rebuilding them.
        $current = $gallery->hasMany(\App\Models\GalleryArchive::class)->getQuery()
            ->where('status', 'ready')->whereNull('superseded_at')->whereNull('storage_deleted_at')->get()
            ->filter(fn ($a) => $a->fingerprint === $archives->fingerprint($gallery, $a->variant))->values();

        $failed = 0;
        $moved = ['photos' => [], 'versions' => []];
        foreach (['photos' => $photos, 'versions' => $versions] as $table => $rows) {
            foreach ($rows as $row) {
                $paths = array_values(array_filter([$row->path, $row->raw_path]));
                $ok = true;
                foreach ($paths as $path) {
                    if (! $this->copy($from, $to, $path)) {
                        $ok = false;
                        $failed++;
                        $this->warn("  could not copy {$path}");
                    }
                }
                if ($ok) {
                    // Older copies may have put the thumbnail on R2 too; clean it up when leaving R2.
                    $moved[$table][] = [$row->id, array_values(array_filter([...$paths, $row->thumb_path ?? null]))];
                }
            }
        }

        // Switch the rows without model events: same bytes, so no ZIP invalidation or updated_at bump.
        foreach (array_chunk(array_column($moved['photos'], 0), 500) as $ids) {
            DB::table('photos')->whereIn('id', $ids)->update(['disk' => $to]);
        }
        foreach (array_chunk(array_column($moved['versions'], 0), 500) as $ids) {
            DB::table('photo_versions')->whereIn('id', $ids)->update(['disk' => $to]);
        }

        // Moving home: the R2 copies are no longer used.
        if (! $toR2) {
            foreach ([...$moved['photos'], ...$moved['versions']] as [, $paths]) {
                Storage::disk($from)->delete($paths);
            }
        }

        foreach ($current as $archive) {
            $archiveDisk = $archive->disk ?: $archives->disk();
            if ($toR2 && $archiveDisk !== $this->r2 && $archive->path) {
                if ($this->copy($archiveDisk, $this->r2, $archive->path)) {
                    Storage::disk($archiveDisk)->delete($archive->path);
                    $archive->disk = $this->r2;
                } else {
                    $failed++;
                    $this->warn("  could not copy ZIP {$archive->path}");
                }
            }
            DB::table('gallery_archives')->where('id', $archive->id)->update([
                'disk' => $archive->disk,
                'fingerprint' => $archives->fingerprint($gallery, $archive->variant),
            ]);
        }

        StorageUsageService::forgetForGallery($gallery->id);
        Cache::forever("gallery:{$gallery->id}:cache_version", Photo::galleryCacheVersion($gallery->id) + 1);

        $summary = sprintf('%s: moved %d photo(s), %d version(s), %d ZIP(s) to %s, %d failed',
            $label, count($moved['photos']), count($moved['versions']), $current->count(), $toR2 ? 'R2' : 'home', $failed);
        $this->info($summary);
        Log::info('galleries:tier '.$summary);

        return $failed;
    }

    /** Copy one object between disks; skips when the target already has the same size. */
    private function copy(string $from, string $to, string $path): bool
    {
        $src = Storage::disk($from);
        $dst = Storage::disk($to);

        try {
            $size = $src->size($path);
        } catch (\Throwable) {
            // No source copy: fine only if the target already has the file (e.g. home always does).
            try {
                return $dst->exists($path);
            } catch (\Throwable) {
                return false;
            }
        }

        try {
            if ($dst->exists($path) && $dst->size($path) === $size) {
                return true;
            }
        } catch (\Throwable) {
        }

        $in = $src->readStream($path);
        if (! is_resource($in)) {
            return false;
        }

        try {
            $dst->writeStream($path, $in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }

        try {
            return $dst->size($path) === $size;
        } catch (\Throwable) {
            return false;
        }
    }
}

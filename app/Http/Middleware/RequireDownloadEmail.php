<?php

namespace App\Http\Middleware;

use App\Models\Gallery;
use App\Models\GalleryArchive;
use App\Models\Photo;
use App\Support\GalleryActivityTracker as Tracker;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gallery activity (Eugene fork): ask for an email before a full-gallery (zip)
 * download, then log every download (single photos too) with the known email.
 */
class RequireDownloadEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $gallery = $request->route('gallery');

        if (! $gallery instanceof Gallery || ! $gallery->is_shared || ! $gallery->is_share_downloadable || Tracker::isOwner($gallery)) {
            return $next($request);
        }

        // Single photos download freely; only full-gallery zips ask for an email.
        $isSinglePhoto = $request->route('photo') instanceof Photo;

        if ($gallery->require_download_email && ! $isSinglePhoto && ! Tracker::email($request)) {
            $request->session()->put('dl_next.'.$gallery->id, $request->fullUrl());

            return redirect()->route('shares.download-email', $gallery);
        }

        $response = $next($request);

        try {
            if ($this->isDelivered($response)) {
                Tracker::ensureEmailListed($gallery, $request);
                Tracker::record($gallery, $request, 'download', ['detail' => $this->describe($request)]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }

    /** A redirect back to the gallery means the zip isn't ready yet: not a download. */
    protected function isDelivered(Response $response): bool
    {
        if ($response instanceof RedirectResponse) {
            return ! str_starts_with((string) parse_url($response->getTargetUrl(), PHP_URL_PATH), '/shares/');
        }

        return $response->isSuccessful();
    }

    protected function describe(Request $request): string
    {
        $photo = $request->route('photo');
        $archive = $request->route('archive');

        if ($photo instanceof Photo) {
            return $photo->name.($request->input('type') === 'raw' ? ' (RAW)' : '');
        }

        if ($archive instanceof GalleryArchive) {
            return ($archive->variant === 'favorites' ? 'Favorites' : 'All photos').' (zip)';
        }

        return ($request->boolean('favorites') ? 'Favorites' : 'All photos').' (zip)';
    }
}

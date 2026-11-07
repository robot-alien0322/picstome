<?php

namespace App\Http\Middleware;

use App\Models\Gallery;
use App\Support\GalleryActivityTracker as Tracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gallery activity (Eugene fork): count visits to a shared gallery page.
 */
class TrackGalleryVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $gallery = $request->route('gallery');

        if (! $gallery instanceof Gallery || ! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return $response;
        }

        if (Tracker::isOwner($gallery) || Tracker::isBot($request)) {
            return $response;
        }

        try {
            $visitor = Tracker::visitorId($request);

            $recent = $gallery->activities()
                ->where('type', 'visit')
                ->where('visitor_id', $visitor)
                ->where('created_at', '>=', now()->subMinutes(Tracker::VISIT_DEDUPE_MINUTES))
                ->exists();

            if (! $recent) {
                Tracker::record($gallery, $request, 'visit');
            }
        } catch (\Throwable $e) {
            report($e); // never break the gallery because of stats
        }

        return $response;
    }
}

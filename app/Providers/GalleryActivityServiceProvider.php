<?php

namespace App\Providers;

use App\Http\Middleware\RequireDownloadEmail;
use App\Http\Middleware\TrackGalleryVisit;
use App\Models\Gallery;
use App\Models\GalleryActivity;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Gallery activity (Eugene fork): visit stats + email-before-download.
 * Hooks upstream routes by name so web.php stays untouched.
 */
class GalleryActivityServiceProvider extends ServiceProvider
{
    public const DOWNLOAD_ROUTES = ['shares.download', 'shares.archives.download', 'shares.photos.download'];

    public function boot(): void
    {
        Gallery::resolveRelationUsing('activities', fn (Gallery $gallery) => $gallery->hasMany(GalleryActivity::class));

        $this->app->booted(function () {
            if ($this->app->routesAreCached()) {
                return;
            }

            Route::middleware('web')->group(base_path('routes/gallery-activity.php'));

            $routes = Route::getRoutes();
            $routes->refreshNameLookups();

            $routes->getByName('shares.show')?->middleware(TrackGalleryVisit::class);

            foreach (self::DOWNLOAD_ROUTES as $name) {
                $routes->getByName($name)?->middleware(RequireDownloadEmail::class);
            }
        });
    }
}

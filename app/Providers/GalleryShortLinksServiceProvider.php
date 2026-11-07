<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Short links (Eugene fork). Own provider + routes file to keep upstream merges easy.
 */
class GalleryShortLinksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            if (! $this->app->routesAreCached()) {
                Route::middleware('web')->group(base_path('routes/gallery-short-links.php'));
            }
        });
    }
}

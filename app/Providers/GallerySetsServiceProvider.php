<?php

namespace App\Providers;

use App\Models\Gallery;
use App\Models\GallerySet;
use App\Models\Photo;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Gallery sets (Eugene fork). Kept in its own provider + routes file so
 * upstream Picstome updates rarely conflict with it.
 */
class GallerySetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gallery::resolveRelationUsing('sets', fn (Gallery $gallery) => $gallery->hasMany(GallerySet::class)->orderBy('position')->orderBy('id'));

        Photo::resolveRelationUsing('gallerySet', fn (Photo $photo) => $photo->belongsTo(GallerySet::class, 'gallery_set_id'));

        $this->app->booted(function () {
            if (! $this->app->routesAreCached()) {
                Route::middleware('web')->group(base_path('routes/gallery-sets.php'));
            }
        });
    }
}

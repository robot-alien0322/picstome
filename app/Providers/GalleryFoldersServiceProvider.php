<?php

namespace App\Providers;

use App\Models\Gallery;
use App\Models\GalleryFolder;
use App\Models\Team;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Gallery folders (Eugene fork). Own provider + routes file to keep upstream merges easy.
 */
class GalleryFoldersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Team::resolveRelationUsing('galleryFolders', fn (Team $team) => $team->hasMany(GalleryFolder::class)->orderBy('position')->orderBy('id'));

        Gallery::resolveRelationUsing('folder', fn (Gallery $gallery) => $gallery->belongsTo(GalleryFolder::class, 'gallery_folder_id'));

        $this->app->booted(function () {
            if (! $this->app->routesAreCached()) {
                Route::middleware('web')->group(base_path('routes/gallery-folders.php'));
            }
        });
    }
}

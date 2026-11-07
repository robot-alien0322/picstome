<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\GallerySetsServiceProvider::class, // Eugene fork: gallery sets
    App\Providers\GalleryFoldersServiceProvider::class, // Eugene fork: gallery folders
    App\Providers\GalleryShortLinksServiceProvider::class, // Eugene fork: short links
];

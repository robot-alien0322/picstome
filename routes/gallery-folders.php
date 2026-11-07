<?php

use Illuminate\Support\Facades\Route;

// Gallery folders (Eugene fork). Not under /galleries/... so it can't collide with /galleries/{gallery}.
Route::livewire('/gallery-folders', 'pages::gallery-folders')->name('gallery-folders')->middleware(['auth', 'verified']);

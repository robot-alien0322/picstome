<?php

use Illuminate\Support\Facades\Route;

// Gallery sets (Eugene fork)
Route::livewire('/galleries/{gallery}/sets', 'pages::galleries.sets')->name('galleries.sets')->middleware(['auth', 'verified']);

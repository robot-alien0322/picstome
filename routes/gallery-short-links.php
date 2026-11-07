<?php

use App\Models\Gallery;
use Illuminate\Support\Facades\Route;

// Short links (Eugene fork): gallery.example.com/g/funk-fest -> full share URL.
Route::get('/g/{code}', function (string $code) {
    $gallery = Gallery::where('short_code', strtolower($code))->where('is_shared', true)->first();

    abort_unless($gallery, 404);

    return redirect()->route('shares.show', ['gallery' => $gallery, 'slug' => $gallery->slug]);
})->where('code', '[A-Za-z0-9-]+')->name('galleries.short');

<?php

use App\Http\Middleware\PasswordProtectGallery;
use App\Models\Gallery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// Gallery activity (Eugene fork).
Route::livewire('/dl/{gallery:ulid}', 'pages::shares.download-email')
    ->name('shares.download-email')
    ->middleware(['throttle:30,1', PasswordProtectGallery::class]);

Route::get('/galleries/{gallery}/activity/emails.csv', function (Gallery $gallery) {
    Gate::authorize('update', $gallery);

    $rows = $gallery->activities()
        ->whereNotNull('email')
        ->selectRaw("email, max(name) as name, min(created_at) as first_seen, max(created_at) as last_seen, sum(case when type = 'download' then 1 else 0 end) as downloads")
        ->groupBy('email')
        ->orderBy('first_seen')
        ->get();

    $tz = config('fork.display_timezone');
    $local = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t, 'UTC')->timezone($tz)->format('Y-m-d H:i:s') : null;

    return response()->streamDownload(function () use ($rows, $tz, $local) {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['email', 'name', "first_seen ($tz)", "last_seen ($tz)", 'downloads']);
        foreach ($rows as $r) {
            fputcsv($out, [$r->email, $r->name, $local($r->first_seen), $local($r->last_seen), (int) $r->downloads]);
        }
        fclose($out);
    }, str($gallery->name)->slug().'-emails.csv', ['Content-Type' => 'text/csv']);
})->name('galleries.activity.emails')->middleware(['auth', 'verified']);

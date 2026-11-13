<?php

// Eugene fork: self-hosted galleries never expire on their own (config fork.unlimited_retention).

use App\Models\Gallery;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['fork.unlimited_retention' => true]));

it('gives lifetime teams unlimited retention', function () {
    $team = Team::factory()->create(['lifetime_at' => now()]);

    expect($team->hasUnlimitedRetention())->toBeTrue();
});

it('puts no retention cap on galleries', function () {
    $team = Team::factory()->create(['lifetime_at' => now()]);
    $gallery = Gallery::factory()->for($team)->create(['created_at' => now()->subYears(2)]);

    expect($gallery->retentionCap())->toBeNull();
});

it('leaves private galleries without a date in the expiration backfill', function () {
    $team = Team::factory()->create(['lifetime_at' => now()]);
    $gallery = Gallery::factory()->for($team)->create(['is_public' => false, 'expiration_date' => null]);

    (require database_path('migrations/2026_11_12_100200_backfill_gallery_expiration_dates.php'))->up();

    expect($gallery->fresh()->expiration_date)->toBeNull();
});

it('still applies upstream retention when turned off', function () {
    config(['fork.unlimited_retention' => false]);
    $team = Team::factory()->create(['lifetime_at' => now()]);

    expect($team->hasUnlimitedRetention())->toBeFalse();
});

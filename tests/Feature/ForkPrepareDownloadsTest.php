<?php

// Eugene fork: client ZIPs prepared ahead of time (button + galleries:prepare-downloads --auto).

use App\Models\Gallery;
use App\Models\GalleryArchive;
use App\Models\Photo;
use App\Models\User;
use App\Services\PrepareDownloadsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    Queue::fake();
    config(['picstome.gallery_archives.enabled' => true, 'fork.prepare_downloads.auto' => true]);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
});

function prepGallery($team, array $attrs = [], ?\Carbon\Carbon $uploaded = null): Gallery
{
    $gallery = Gallery::factory()->shared()->downloadable()->for($team)->create($attrs);
    Photo::factory()->for($gallery)->create(['disk' => 's3', 'uploaded_at' => $uploaded ?? now()->subHours(3)]);

    return $gallery;
}

it('auto prepares both client ZIPs for a quiet shared gallery', function () {
    $gallery = prepGallery($this->team);

    $this->artisan('galleries:prepare-downloads', ['--auto' => true])->assertSuccessful();

    expect(GalleryArchive::where('gallery_id', $gallery->id)->pluck('variant')->sort()->values()->all())
        ->toBe(['all', 'all@web']);
});

it('waits while uploads are recent and skips unshared galleries', function () {
    $recent = prepGallery($this->team, [], now()->subMinutes(10));
    $private = Gallery::factory()->for($this->team)->create(['is_shared' => false]);
    Photo::factory()->for($private)->create(['uploaded_at' => now()->subDay()]);

    $this->artisan('galleries:prepare-downloads', ['--auto' => true])->assertSuccessful();

    expect(GalleryArchive::count())->toBe(0);
});

it('does not start a second build while one is running', function () {
    $gallery = prepGallery($this->team);
    $service = app(PrepareDownloadsService::class);

    expect($service->prepare($gallery))->toBe(2)
        ->and($service->prepare($gallery))->toBe(0)
        ->and(GalleryArchive::count())->toBe(2);
});

it('does not retry a recent failure on its own, but the button does', function () {
    $gallery = prepGallery($this->team);
    $service = app(PrepareDownloadsService::class);
    $service->prepare($gallery);
    GalleryArchive::query()->update(['status' => 'failed']);

    expect($service->prepare($gallery, null, retryFailed: false))->toBe(0)
        ->and($service->prepare($gallery))->toBe(2);
});

it('owner sees the status and can prepare from the Download tab', function () {
    $gallery = prepGallery($this->team);

    Livewire::actingAs($this->user)
        ->test('gallery-prepare-downloads', ['gallery' => $gallery])
        ->assertSee('Not prepared')
        ->call('prepare')
        ->assertSee('Preparing');

    expect(GalleryArchive::where('gallery_id', $gallery->id)->count())->toBe(2);
});

it('other teams cannot open the panel', function () {
    $gallery = prepGallery($this->team);
    $other = User::factory()->withPersonalTeam()->create();

    Livewire::actingAs($other)->test('gallery-prepare-downloads', ['gallery' => $gallery])->assertForbidden();
});

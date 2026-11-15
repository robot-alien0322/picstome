<?php

// Eugene fork: R2 tiering (galleries:tier). Garage ("s3") keeps everything; R2 holds active galleries.

use App\Models\Gallery;
use App\Models\GalleryActivity;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['fork.r2_tiering.enabled' => true]);
    Storage::fake('s3');
    Storage::fake('r2');
});

function tierPhoto(Gallery $gallery, string $name, array $attrs = []): Photo
{
    $path = "galleries/{$gallery->id}/{$name}";
    Storage::disk('s3')->put($path, str_repeat('x', 2048));
    Storage::disk('s3')->put("{$path}.thumb.webp", 'thumb');

    return Photo::factory()->for($gallery)->create(array_merge([
        'name' => $name, 'path' => $path, 'thumb_path' => "{$path}.thumb.webp",
        'disk' => 's3', 'size' => 2048, 'stored_size' => 2048, 'uploaded_at' => now()->subDay(),
    ], $attrs));
}

it('copies photos to R2, keeps the home copy, and moves them back', function () {
    $gallery = Gallery::factory()->create();
    $photo = tierPhoto($gallery, 'a.jpg');
    $updated = $photo->fresh()->updated_at;

    $this->artisan('galleries:tier', ['gallery' => [$gallery->id], '--to' => 'r2'])->assertSuccessful();

    expect($photo->fresh()->disk)->toBe('r2')
        ->and($photo->fresh()->updated_at->equalTo($updated))->toBeTrue();
    Storage::disk('r2')->assertExists([$photo->path, $photo->thumb_path]);
    Storage::disk('s3')->assertExists([$photo->path, $photo->thumb_path]);

    $this->artisan('galleries:tier', ['gallery' => [$gallery->id], '--to' => 'home'])->assertSuccessful();

    expect($photo->fresh()->disk)->toBe('s3');
    Storage::disk('r2')->assertMissing($photo->path);
    Storage::disk('s3')->assertExists($photo->path);
});

it('leaves videos and photos still processing at home', function () {
    $gallery = Gallery::factory()->create();
    $video = tierPhoto($gallery, 'clip.mp4');
    $pending = tierPhoto($gallery, 'b.jpg', ['status' => 'pending']);

    $this->artisan('galleries:tier', ['gallery' => [$gallery->id], '--to' => 'r2'])->assertSuccessful();

    expect($video->fresh()->disk)->toBe('s3')->and($pending->fresh()->disk)->toBe('s3');
});

it('auto: sends active quiet galleries to R2 and quiet old ones home', function () {
    $fresh = Gallery::factory()->create();
    $a = tierPhoto($fresh, 'a.jpg');

    $uploading = Gallery::factory()->create();
    $b = tierPhoto($uploading, 'b.jpg', ['uploaded_at' => now()]);

    $old = Gallery::factory()->create(['created_at' => now()->subDays(200)]);
    $c = tierPhoto($old, 'c.jpg', ['disk' => 'r2']);
    Storage::disk('r2')->put($c->path, str_repeat('x', 2048));

    $oldButVisited = Gallery::factory()->create(['created_at' => now()->subDays(200)]);
    $d = tierPhoto($oldButVisited, 'd.jpg', ['disk' => 'r2']);
    GalleryActivity::create(['gallery_id' => $oldButVisited->id, 'type' => 'visit']);

    $this->artisan('galleries:tier', ['--auto' => true])->assertSuccessful();

    expect($a->fresh()->disk)->toBe('r2')
        ->and($b->fresh()->disk)->toBe('s3')
        ->and($c->fresh()->disk)->toBe('s3')
        ->and($d->fresh()->disk)->toBe('r2');
    Storage::disk('r2')->assertMissing($c->path);
});

it('does nothing on auto when tiering is off', function () {
    config(['fork.r2_tiering.enabled' => false]);
    $a = tierPhoto(Gallery::factory()->create(), 'a.jpg');

    $this->artisan('galleries:tier', ['--auto' => true])->assertSuccessful();

    expect($a->fresh()->disk)->toBe('s3');
});

it('deleting a photo on R2 also removes its home copy', function () {
    $gallery = Gallery::factory()->create();
    $photo = tierPhoto($gallery, 'a.jpg');
    $this->artisan('galleries:tier', ['gallery' => [$gallery->id], '--to' => 'r2'])->assertSuccessful();

    $photo->fresh()->deleteFromDisk()->delete();

    Storage::disk('r2')->assertMissing($photo->path);
    Storage::disk('s3')->assertMissing($photo->path);
});

<?php

// Eugene fork: gallery folders

use App\Models\Gallery;
use App\Models\GalleryFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    actingAs($this->user);
});

test('folders page loads', function () {
    $this->get(route('gallery-folders'))->assertOk();
});

test('owner can create a folder and file a gallery in it', function () {
    $gallery = Gallery::factory()->for($this->team)->create();

    $c = Livewire::test('pages::gallery-folders')->set('newFolderName', 'Concerts')->call('createFolder');
    $folder = GalleryFolder::first();
    expect($folder->name)->toBe('Concerts')->and($folder->team_id)->toBe($this->team->id);

    $c->call('setFolder', $gallery->id, (string) $folder->id);
    expect($gallery->fresh()->gallery_folder_id)->toBe($folder->id);

    $c->call('setFolder', $gallery->id, '');
    expect($gallery->fresh()->gallery_folder_id)->toBeNull();
});

test('deleting a folder keeps its galleries', function () {
    $folder = GalleryFolder::create(['team_id' => $this->team->id, 'name' => 'Weddings', 'position' => 1]);
    $gallery = Gallery::factory()->for($this->team)->create(['gallery_folder_id' => $folder->id]);

    Livewire::test('pages::gallery-folders')->call('deleteFolder', $folder->id);

    expect(GalleryFolder::count())->toBe(0)->and($gallery->fresh()->gallery_folder_id)->toBeNull();
});

test('cannot use another team\'s folder or gallery', function () {
    $other = User::factory()->withPersonalTeam()->create();
    $theirFolder = GalleryFolder::create(['team_id' => $other->currentTeam->id, 'name' => 'Theirs', 'position' => 1]);
    $theirGallery = Gallery::factory()->for($other->currentTeam)->create();
    $mine = Gallery::factory()->for($this->team)->create();

    Livewire::test('pages::gallery-folders')->call('setFolder', $mine->id, (string) $theirFolder->id)->assertNotFound();
    Livewire::test('pages::gallery-folders')->call('setFolder', $theirGallery->id, '')->assertNotFound();

    expect($mine->fresh()->gallery_folder_id)->toBeNull();
});

test('galleries page filters by folder', function () {
    $folder = GalleryFolder::create(['team_id' => $this->team->id, 'name' => 'Concerts', 'position' => 1]);
    $inFolder = Gallery::factory()->for($this->team)->create(['name' => 'Funk Fest', 'gallery_folder_id' => $folder->id]);
    $unfiled = Gallery::factory()->for($this->team)->create(['name' => 'Smith Wedding']);

    $c = Livewire::test('pages::galleries');
    $c->assertSee('Funk Fest')->assertSee('Smith Wedding')->assertSeeHtml('data-gallery-folders');

    $c->set('folder', (string) $folder->id)->assertSee('Funk Fest')->assertDontSee('Smith Wedding');
    $c->set('folder', 'none')->assertSee('Smith Wedding')->assertDontSee('Funk Fest');
});

test('galleries page without folders shows no folder tabs', function () {
    Gallery::factory()->for($this->team)->create();

    Livewire::test('pages::galleries')->assertDontSeeHtml('data-gallery-folders');
});

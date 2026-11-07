<?php

// Eugene fork: gallery sets

use App\Models\Gallery;
use App\Models\GallerySet;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->gallery = Gallery::factory()->shared()->for($this->team)->create();
    $this->a = Photo::factory()->for($this->gallery)->create(['status' => 'processed', 'name' => 'a.jpg']);
    $this->b = Photo::factory()->for($this->gallery)->create(['status' => 'processed', 'name' => 'b.jpg']);
    $this->c = Photo::factory()->for($this->gallery)->create(['status' => 'processed', 'name' => 'c.jpg']);
});

test('owner can open the sets page', function () {
    actingAs($this->user)->get(route('galleries.sets', $this->gallery))->assertOk();
});

test('other users cannot open the sets page', function () {
    $other = User::factory()->withPersonalTeam()->create();

    actingAs($other)->get(route('galleries.sets', $this->gallery))->assertForbidden();
});

test('owner can create a set and assign photos to it', function () {
    actingAs($this->user);

    $component = Livewire::test('pages::galleries.sets', ['gallery' => $this->gallery])
        ->set('newSetName', 'Ceremony')
        ->call('createSet');

    $set = $this->gallery->sets()->first();
    expect($set->name)->toBe('Ceremony');

    $component->set('targetSet', $set->id)
        ->call('toggle', $this->a->id)
        ->call('toggle', $this->c->id)
        ->call('assign');

    expect($this->a->fresh()->gallery_set_id)->toBe($set->id)
        ->and($this->b->fresh()->gallery_set_id)->toBeNull()
        ->and($this->c->fresh()->gallery_set_id)->toBe($set->id);
});

test('deleting a set keeps its photos in the gallery', function () {
    actingAs($this->user);
    $set = GallerySet::create(['gallery_id' => $this->gallery->id, 'name' => 'Reception', 'position' => 1]);
    $this->a->update(['gallery_set_id' => $set->id]);

    Livewire::test('pages::galleries.sets', ['gallery' => $this->gallery])->call('deleteSet', $set->id);

    expect(GallerySet::count())->toBe(0)
        ->and($this->a->fresh())->not->toBeNull()
        ->and($this->a->fresh()->gallery_set_id)->toBeNull();
});

test('photos cannot be assigned to another gallery\'s set', function () {
    actingAs($this->user);
    $otherGallery = Gallery::factory()->for($this->team)->create();
    $foreign = GallerySet::create(['gallery_id' => $otherGallery->id, 'name' => 'Nope', 'position' => 1]);

    Livewire::test('pages::galleries.sets', ['gallery' => $this->gallery])
        ->set('targetSet', $foreign->id)
        ->call('toggle', $this->a->id)
        ->call('assign')
        ->assertNotFound();

    expect($this->a->fresh()->gallery_set_id)->toBeNull();
});

test('shared gallery filters photos by set and keeps order', function () {
    $set = GallerySet::create(['gallery_id' => $this->gallery->id, 'name' => 'Ceremony', 'position' => 1]);
    $this->c->update(['gallery_set_id' => $set->id]);
    $this->a->update(['gallery_set_id' => $set->id]);

    $component = Livewire::test('pages::shares.show', ['gallery' => $this->gallery, 'slug' => $this->gallery->slug]);

    expect($component->instance()->photoIds->all())->toBe([$this->a->id, $this->b->id, $this->c->id])
        ->and($component->instance()->gallerySets->pluck('name')->all())->toBe(['Ceremony']);

    $component->set('set', (string) $set->id);

    expect($component->instance()->photoIds->all())->toBe([$this->a->id, $this->c->id]);
    $component->assertSee('Ceremony');
});

test('shared gallery without sets shows no set tabs', function () {
    Livewire::test('pages::shares.show', ['gallery' => $this->gallery, 'slug' => $this->gallery->slug])
        ->assertDontSeeHtml('data-gallery-sets');
});

test('empty sets are hidden from clients', function () {
    GallerySet::create(['gallery_id' => $this->gallery->id, 'name' => 'Empty', 'position' => 1]);

    $component = Livewire::test('pages::shares.show', ['gallery' => $this->gallery, 'slug' => $this->gallery->slug]);

    expect($component->instance()->gallerySets)->toBeEmpty();
});

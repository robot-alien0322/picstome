<?php

// Eugene fork: gallery short links

use App\Models\Gallery;
use App\Models\Team;
use App\Models\User;
use App\Support\GalleryShortLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

describe('Gallery short links (Eugene fork)', function () {
    beforeEach(function () {
        $this->user = User::factory()->withPersonalTeam()->create();
        $this->team = $this->user->currentTeam;
        $this->gallery = Gallery::factory()->for($this->team)->create(['name' => 'Funk Fest', 'is_shared' => true]);
    });

    it('assigns a random short code on first view', function () {
        actingAs($this->user);

        Livewire::test('gallery-short-link', ['gallery' => $this->gallery])->assertSet('code', fn ($c) => strlen($c) === 6);

        expect($this->gallery->fresh()->short_code)->toHaveLength(6);
    });

    it('redirects a short link to the share page', function () {
        $this->gallery->forceFill(['short_code' => 'funk-fest'])->save();

        get('/g/funk-fest')->assertRedirect(route('shares.show', ['gallery' => $this->gallery, 'slug' => $this->gallery->slug]));
        get('/g/FUNK-FEST')->assertRedirect();
    });

    it('does not reveal galleries that are not shared', function () {
        $this->gallery->forceFill(['short_code' => 'secret', 'is_shared' => false])->save();

        get('/g/secret')->assertNotFound();
        get('/g/nope')->assertNotFound();
    });

    it('lets the owner customize the code and normalizes it', function () {
        actingAs($this->user);

        Livewire::test('gallery-short-link', ['gallery' => $this->gallery])
            ->set('code', 'Funk Fest 2026!')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->gallery->fresh()->short_code)->toBe('funk-fest-2026');
    });

    it('rejects a code already in use', function () {
        Gallery::factory()->for($this->team)->create(['short_code' => 'taken']);
        actingAs($this->user);

        Livewire::test('gallery-short-link', ['gallery' => $this->gallery])
            ->set('code', 'taken')
            ->call('save')
            ->assertHasErrors('code');
    });

    it('forbids other teams', function () {
        $other = User::factory()->withPersonalTeam()->create();
        actingAs($other);

        Livewire::test('gallery-short-link', ['gallery' => $this->gallery])->assertForbidden();
    });

    it('generates codes without look-alike characters', function () {
        foreach (range(1, 50) as $_) {
            expect(GalleryShortLinks::randomCode())->toMatch('/^[a-hj-km-np-z2-9]{6}$/');
        }
    });
});

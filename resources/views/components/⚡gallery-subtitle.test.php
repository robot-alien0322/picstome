<?php

// Eugene fork: gallery subtitle

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

describe('Gallery subtitle (Eugene fork)', function () {
    beforeEach(function () {
        $this->user = User::factory()->withPersonalTeam()->create();
        $this->gallery = Gallery::factory()->for($this->user->currentTeam)->create(['name' => 'Funk Fest', 'is_shared' => true]);
    });

    it('saves a trimmed subtitle', function () {
        actingAs($this->user);

        Livewire::test('gallery-subtitle', ['gallery' => $this->gallery])
            ->set('subtitle', '  November 1, 2026 · Lafayette Square ')
            ->assertSet('saved', true);

        expect($this->gallery->fresh()->share_subtitle)->toBe('November 1, 2026 · Lafayette Square');
    });

    it('stores blank as null', function () {
        actingAs($this->user);
        $this->gallery->forceFill(['share_subtitle' => 'Old'])->save();

        Livewire::test('gallery-subtitle', ['gallery' => $this->gallery])->set('subtitle', '   ');

        expect($this->gallery->fresh()->share_subtitle)->toBeNull();
    });

    it('rejects other teams', function () {
        actingAs(User::factory()->withPersonalTeam()->create());

        Livewire::test('gallery-subtitle', ['gallery' => $this->gallery])->assertForbidden();
    });

    it('shows the subtitle on the client page and nothing when blank', function () {
        $url = route('shares.show', ['gallery' => $this->gallery, 'slug' => $this->gallery->slug]);

        get($url)->assertOk()->assertDontSee('data-fork-subtitle', false);

        $this->gallery->forceFill(['share_subtitle' => 'Lafayette Square'])->save();

        get($url)->assertOk()->assertSee('data-fork-subtitle', false)->assertSee('Lafayette Square');
    });
});

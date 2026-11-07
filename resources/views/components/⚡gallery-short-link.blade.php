<?php

use App\Models\Gallery;
use App\Support\GalleryShortLinks;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public Gallery $gallery;

    public string $code = '';

    public bool $editing = false;

    public function mount(): void
    {
        Gate::authorize('update', $this->gallery);

        $this->code = GalleryShortLinks::ensure($this->gallery);
    }

    public function save(): void
    {
        Gate::authorize('update', $this->gallery);

        $this->code = GalleryShortLinks::normalize($this->code);

        $this->validate([
            'code' => [
                'required',
                'regex:'.GalleryShortLinks::PATTERN,
                Rule::unique('galleries', 'short_code')->ignore($this->gallery->id),
            ],
        ], [
            'code.regex' => __('Use 3-40 letters, numbers or dashes.'),
            'code.unique' => __('That short link is already used by another gallery.'),
        ]);

        $this->gallery->forceFill(['short_code' => $this->code])->saveQuietly();

        $this->editing = false;
    }

    public function randomize(): void
    {
        Gate::authorize('update', $this->gallery);

        $this->code = GalleryShortLinks::randomCode();
        $this->gallery->forceFill(['short_code' => $this->code])->saveQuietly();
        $this->resetValidation();
        $this->editing = false;
    }

    public function suggestFromName(): void
    {
        $this->code = GalleryShortLinks::normalize($this->gallery->name);
        $this->editing = true;
    }
}; ?>

<div class="space-y-2" data-gallery-short-link>
    @if ($editing)
        <form wire:submit="save" class="space-y-2">
            <x-ts-input wire:model="code" :label="__('Short link')" :prefix="url('/g').'/'" />
            <p class="text-xs text-zinc-500">
                {{ __('Readable names like "funk-fest" are easy to guess. Use a password on galleries that should stay private.') }}
            </p>
            <div class="flex gap-2">
                <x-ts-button type="submit" variant="primary" sm>{{ __('Save') }}</x-ts-button>
                <x-ts-button type="button" wire:click="$set('editing', false)" variant="subtle" sm>{{ __('Cancel') }}</x-ts-button>
            </div>
        </form>
    @else
        <x-ts-input icon="link" :value="url('/g/'.$gallery->short_code)" :label="__('Short link')" readonly copyable />
        <div class="flex gap-3 text-xs">
            <button type="button" wire:click="suggestFromName" class="text-zinc-500 hover:underline">{{ __('Use gallery name') }}</button>
            <button type="button" wire:click="$set('editing', true)" class="text-zinc-500 hover:underline">{{ __('Customize') }}</button>
            <button type="button" wire:click="randomize" class="text-zinc-500 hover:underline">{{ __('Random') }}</button>
        </div>
    @endif
</div>

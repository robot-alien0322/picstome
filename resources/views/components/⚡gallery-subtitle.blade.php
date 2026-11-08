<?php

// Eugene fork: subtitle under the gallery title on client pages (e.g. date and venue).

use App\Models\Gallery;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Gallery $gallery;

    public string $subtitle = '';

    public bool $saved = false;

    public function mount(): void
    {
        Gate::authorize('update', $this->gallery);

        $this->subtitle = (string) $this->gallery->share_subtitle;
    }

    public function updatedSubtitle(): void
    {
        Gate::authorize('update', $this->gallery);

        $this->subtitle = trim($this->subtitle);

        $this->validate(['subtitle' => ['nullable', 'string', 'max:150']]);

        $this->gallery->forceFill(['share_subtitle' => $this->subtitle === '' ? null : $this->subtitle])->saveQuietly();

        $this->saved = true;
    }
}; ?>

<div class="space-y-1" data-gallery-subtitle>
    <x-ts-input
        wire:model.blur="subtitle"
        x-on:keydown.enter.prevent="$el.blur()"
        x-on:input="$wire.saved = false"
        :label="__('Subtitle')"
        :placeholder="__('e.g. November 1, 2026 · Lafayette Square')"
    />
    <p class="text-xs text-zinc-500">
        <span x-show="!$wire.saved">{{ __('Shown under the gallery title. Leave blank for none.') }}</span>
        <span x-show="$wire.saved" x-cloak>{{ __('Saved.') }}</span>
    </p>
</div>

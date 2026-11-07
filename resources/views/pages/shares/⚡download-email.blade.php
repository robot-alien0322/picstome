<?php

// Eugene fork: ask for an email before a client download.

use App\Models\Gallery;
use App\Support\GalleryActivityTracker as Tracker;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public Gallery $gallery;

    public string $email = '';

    public string $name = '';

    public bool $done = false;

    public ?string $next = null;

    public function mount(Gallery $gallery)
    {
        abort_unless($gallery->is_shared && $gallery->is_share_downloadable, 404);

        if (Tracker::email(request())) {
            return $this->redirect($this->nextUrl(pull: true));
        }
    }

    public function submit(): void
    {
        $this->email = strtolower(trim($this->email));
        $this->name = trim($this->name);

        $this->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        Tracker::rememberEmail(request(), $this->email);
        Tracker::record($this->gallery, request(), 'email', [
            'email' => $this->email,
            'name' => $this->name !== '' ? $this->name : null,
        ]);

        $this->next = $this->nextUrl(pull: true);
        $this->done = true;
    }

    protected function nextUrl(bool $pull = false): string
    {
        $key = 'dl_next.'.$this->gallery->id;
        $next = $pull ? session()->pull($key) : session($key);
        $prefix = url('/shares/'.$this->gallery->ulid.'/');

        return is_string($next) && str_starts_with($next, $prefix)
            ? $next
            : route('shares.show', ['gallery' => $this->gallery, 'slug' => $this->gallery->slug]);
    }

    public function galleryUrl(): string
    {
        return route('shares.show', ['gallery' => $this->gallery, 'slug' => $this->gallery->slug]);
    }
}; ?>

<div class="mx-auto mt-4 w-full md:w-96 lg:mt-8" data-download-email>
    @if ($done)
        <div class="space-y-4" x-data x-init="setTimeout(() => window.location.href = @js($next), 300)">
            <x-ts-heading size="lg">{{ __('Thank you!') }}</x-ts-heading>
            <x-ts-subheading>{{ __('Your download is starting. If it doesn\'t, use the button below.') }}</x-ts-subheading>
            <div class="flex gap-3">
                <x-ts-button :href="$next" variant="primary" icon="cloud-arrow-down">{{ __('Download') }}</x-ts-button>
                <x-ts-button :href="$this->galleryUrl()" variant="subtle">{{ __('Back to gallery') }}</x-ts-button>
            </div>
        </div>
    @else
        <form wire:submit="submit" class="space-y-6">
            <div>
                <x-ts-heading size="lg">{{ __('Download photos') }}</x-ts-heading>
                <x-ts-subheading>{{ __('Enter your email to download from ":gallery".', ['gallery' => $gallery->name]) }}</x-ts-subheading>
            </div>

            <x-ts-input wire:model="email" :label="__('Email')" type="email" autocomplete="email" required />
            <x-ts-input wire:model="name" :label="__('Name (optional)')" autocomplete="name" />

            <p class="text-xs text-zinc-500">
                {{ __('Your email is shared only with :studio so they know who downloaded these photos.', ['studio' => $gallery->team->name]) }}
            </p>

            <div class="flex items-center">
                <a href="{{ $this->galleryUrl() }}" class="text-sm text-zinc-500 hover:underline">{{ __('Back to gallery') }}</a>
                <x-ts-spacer />
                <x-ts-button type="submit" variant="primary">{{ __('Continue') }}</x-ts-button>
            </div>
        </form>
    @endif
</div>

<?php

use App\Models\Gallery;
use App\Models\GallerySet;
use App\Models\Photo;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Gallery sets admin (Eugene fork): create/rename/reorder/delete sets and
 * assign photos to them. Clients see sets as tabs on the shared gallery.
 */
new class extends Component
{
    public Gallery $gallery;

    public string $newSetName = '';

    /** @var array<int, int> selected photo ids */
    public array $selected = [];

    /** 'all', 'unsorted', or a set id */
    #[Url]
    public string $filter = 'all';

    public ?int $targetSet = null;

    public ?int $renamingId = null;

    public string $renameValue = '';

    public function mount(Gallery $gallery): void
    {
        $this->authorize('update', $gallery);
    }

    #[Computed]
    public function sets()
    {
        return $this->gallery->sets()->withCount(['photos' => fn ($q) => $q->ready()])->get();
    }

    #[Computed]
    public function photos()
    {
        $order = $this->gallery->readyPhotoIds()->flip();

        $query = $this->gallery->photos()->ready();

        if ($this->filter === 'unsorted') {
            $query->whereNull('gallery_set_id');
        } elseif (ctype_digit($this->filter)) {
            $query->where('gallery_set_id', (int) $this->filter);
        }

        return $query->get()->sortBy(fn (Photo $p) => $order[$p->id] ?? PHP_INT_MAX)->values();
    }

    #[Computed]
    public function unsortedCount(): int
    {
        return $this->gallery->photos()->ready()->whereNull('gallery_set_id')->count();
    }

    protected function refresh(): void
    {
        unset($this->sets, $this->photos, $this->unsortedCount);
    }

    protected function ownSet(int $id): GallerySet
    {
        return $this->gallery->sets()->whereKey($id)->firstOrFail();
    }

    public function createSet(): void
    {
        $this->authorize('update', $this->gallery);
        $this->validate(['newSetName' => 'required|string|max:80']);

        $this->gallery->sets()->create([
            'name' => trim($this->newSetName),
            'position' => (int) $this->gallery->sets()->max('position') + 1,
        ]);

        $this->newSetName = '';
        $this->refresh();
    }

    public function startRename(int $id): void
    {
        $this->renamingId = $id;
        $this->renameValue = $this->ownSet($id)->name;
    }

    public function saveRename(): void
    {
        $this->authorize('update', $this->gallery);
        $this->validate(['renameValue' => 'required|string|max:80']);
        $this->ownSet($this->renamingId)->update(['name' => trim($this->renameValue)]);
        $this->renamingId = null;
        $this->refresh();
    }

    public function moveSet(int $id, int $direction): void
    {
        $this->authorize('update', $this->gallery);
        $sets = $this->gallery->sets()->get()->values();
        $index = $sets->search(fn ($s) => $s->id === $id);
        $swap = $index + $direction;

        if ($index === false || $swap < 0 || $swap >= $sets->count()) {
            return;
        }

        $ordered = $sets->all();
        [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];

        foreach ($ordered as $pos => $set) {
            $set->update(['position' => $pos + 1]);
        }

        $this->refresh();
    }

    public function deleteSet(int $id): void
    {
        $this->authorize('update', $this->gallery);
        $set = $this->ownSet($id);
        // Photos are kept; they just become unsorted.
        $set->photos()->update(['gallery_set_id' => null]);
        $set->delete();

        if ($this->filter === (string) $id) {
            $this->filter = 'all';
        }

        if ($this->targetSet === $id) {
            $this->targetSet = null;
        }

        $this->refresh();
    }

    public function toggle(int $photoId): void
    {
        $this->selected = in_array($photoId, $this->selected, true)
            ? array_values(array_diff($this->selected, [$photoId]))
            : [...$this->selected, $photoId];
    }

    public function selectAllShown(): void
    {
        $this->selected = $this->photos->pluck('id')->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function assign(): void
    {
        $this->authorize('update', $this->gallery);

        if (! $this->targetSet || ! $this->selected) {
            return;
        }

        $set = $this->ownSet($this->targetSet);

        $this->gallery->photos()->whereKey($this->selected)->update(['gallery_set_id' => $set->id]);
        $this->selected = [];
        $this->refresh();
    }

    public function unassign(): void
    {
        $this->authorize('update', $this->gallery);

        if (! $this->selected) {
            return;
        }

        $this->gallery->photos()->whereKey($this->selected)->update(['gallery_set_id' => null]);
        $this->selected = [];
        $this->refresh();
    }

    public function updatedFilter(): void
    {
        $this->selected = [];
        $this->refresh();
    }
};
?>

<div>
    <div class="max-lg:hidden">
        <x-ts-button :href="route('galleries.show', $gallery)" icon="chevron-left" variant="subtle" inset>
            {{ $gallery->name }}
        </x-ts-button>
    </div>

    <div class="mt-4 lg:mt-8">
        <x-ts-heading level="1" size="xl">{{ __('Sets') }}</x-ts-heading>
        <x-ts-subheading class="mt-2">
            {{ __('Group this gallery into sets, like Getting Ready, Ceremony and Reception. Clients see each set as a tab; photos without a set still show under All.') }}
        </x-ts-subheading>
    </div>

    <div class="mt-8 grid gap-8 lg:grid-cols-[18rem_1fr]">
        {{-- Sets list --}}
        <div>
            <form wire:submit="createSet" class="flex gap-2">
                <div class="flex-1"><x-ts-input wire:model="newSetName" :placeholder="__('New set name')" /></div>
                <x-ts-button type="submit" variant="primary" class="btn-ink">{{ __('Add') }}</x-ts-button>
            </form>

            <div class="mt-4 space-y-1 text-sm">
                <button type="button" wire:click="$set('filter', 'all')"
                    @class(['flex w-full justify-between rounded-md px-3 py-2 text-left', 'bg-zinc-100 font-semibold dark:bg-white/10' => $filter === 'all'])>
                    <span>{{ __('All photos') }}</span><span class="text-zinc-500">{{ $gallery->readyPhotoIds()->count() }}</span>
                </button>
                <button type="button" wire:click="$set('filter', 'unsorted')"
                    @class(['flex w-full justify-between rounded-md px-3 py-2 text-left', 'bg-zinc-100 font-semibold dark:bg-white/10' => $filter === 'unsorted'])>
                    <span>{{ __('Not in a set') }}</span><span class="text-zinc-500">{{ $this->unsortedCount }}</span>
                </button>

                @foreach ($this->sets as $set)
                    <div wire:key="set-{{ $set->id }}" @class(['group flex items-center gap-1 rounded-md px-1', 'bg-zinc-100 dark:bg-white/10' => $filter === (string) $set->id])>
                        @if ($renamingId === $set->id)
                            <form wire:submit="saveRename" class="flex flex-1 gap-1 py-1">
                                <div class="flex-1"><x-ts-input wire:model="renameValue" /></div>
                                <x-ts-button type="submit" size="sm" variant="primary" class="btn-ink">{{ __('Save') }}</x-ts-button>
                            </form>
                        @else
                            <button type="button" wire:click="$set('filter', '{{ $set->id }}')" @class(['flex flex-1 justify-between px-2 py-2 text-left', 'font-semibold' => $filter === (string) $set->id])>
                                <span class="truncate">{{ $set->name }}</span><span class="text-zinc-500">{{ $set->photos_count }}</span>
                            </button>
                            <div class="flex shrink-0 items-center opacity-60 group-hover:opacity-100">
                                <button type="button" title="{{ __('Move up') }}" wire:click="moveSet({{ $set->id }}, -1)" class="px-1">&uarr;</button>
                                <button type="button" title="{{ __('Move down') }}" wire:click="moveSet({{ $set->id }}, 1)" class="px-1">&darr;</button>
                                <button type="button" title="{{ __('Rename') }}" wire:click="startRename({{ $set->id }})" class="px-1">&#9998;</button>
                                <button type="button" title="{{ __('Delete set') }}" wire:click="deleteSet({{ $set->id }})"
                                    wire:confirm="{{ __('Delete this set? Its photos stay in the gallery, just without a set.') }}" class="px-1 text-red-600">&times;</button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Photos --}}
        <div>
            <div class="sticky top-0 z-30 flex flex-wrap items-center gap-3 border-b border-zinc-200 bg-white/90 py-3 backdrop-blur dark:border-white/10 dark:bg-zinc-900/90">
                <span class="text-sm text-zinc-600 dark:text-white/70">
                    {{ count($selected) }} {{ __('selected') }}
                </span>
                <x-ts-button size="sm" variant="secondary" wire:click="selectAllShown">{{ __('Select all shown') }}</x-ts-button>
                @if ($selected)
                    <x-ts-button size="sm" variant="secondary" wire:click="clearSelection">{{ __('Clear') }}</x-ts-button>
                @endif
                <div class="ms-auto flex flex-wrap items-center gap-2">
                    <select wire:model="targetSet" class="rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-white/20 dark:bg-zinc-800">
                        <option value="">{{ __('Choose a set…') }}</option>
                        @foreach ($this->sets as $set)
                            <option value="{{ $set->id }}">{{ $set->name }}</option>
                        @endforeach
                    </select>
                    <x-ts-button size="sm" variant="primary" class="btn-ink" wire:click="assign" :disabled="! $selected">{{ __('Add to set') }}</x-ts-button>
                    <x-ts-button size="sm" variant="secondary" wire:click="unassign" :disabled="! $selected">{{ __('Remove from set') }}</x-ts-button>
                </div>
            </div>

            @if ($this->sets->isEmpty())
                <p class="mt-6 text-sm text-zinc-500">{{ __('Create a set on the left, then select photos here and add them to it.') }}</p>
            @endif

            <div class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                @forelse ($this->photos as $photo)
                    @php($isSel = in_array($photo->id, $selected, true))
                    <button type="button" wire:key="p-{{ $photo->id }}" wire:click="toggle({{ $photo->id }})"
                        @class(['relative aspect-square overflow-hidden rounded-md bg-zinc-100 outline-offset-2 dark:bg-white/5', 'outline outline-3 outline-(--color-accent,#A2611C)' => $isSel])>
                        @if ($photo->thumbnail_url)
                            <img loading="lazy" src="{{ $photo->thumbnail_url }}" alt="" @class(['h-full w-full object-cover', 'opacity-70' => $isSel])>
                        @endif
                        @if ($photo->gallery_set_id && $filter === 'all')
                            <span class="absolute bottom-1 left-1 max-w-[90%] truncate rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white">
                                {{ $this->sets->firstWhere('id', $photo->gallery_set_id)?->name }}
                            </span>
                        @endif
                        @if ($isSel)
                            <span class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-(--color-accent,#A2611C) text-xs text-white">&#10003;</span>
                        @endif
                    </button>
                @empty
                    <p class="col-span-full text-sm text-zinc-500">{{ __('No photos here.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

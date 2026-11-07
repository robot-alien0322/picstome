<?php

use App\Models\Gallery;
use App\Models\GalleryFolder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * Gallery folders admin (Eugene fork): create/rename/reorder/delete folders
 * and choose a folder for each gallery.
 */
new class extends Component
{
    public string $newFolderName = '';

    public ?int $renamingId = null;

    public string $renameValue = '';

    #[Computed]
    public function team()
    {
        return Auth::user()?->currentTeam;
    }

    #[Computed]
    public function folders()
    {
        return $this->team->galleryFolders()->withCount('galleries')->get();
    }

    #[Computed]
    public function galleries()
    {
        return $this->team->galleries()->with('folder')->latest()->get();
    }

    protected function refresh(): void
    {
        unset($this->folders, $this->galleries);
    }

    protected function ownFolder(int $id): GalleryFolder
    {
        return $this->team->galleryFolders()->whereKey($id)->firstOrFail();
    }

    public function createFolder(): void
    {
        $this->validate(['newFolderName' => 'required|string|max:60']);

        $this->team->galleryFolders()->create([
            'name' => trim($this->newFolderName),
            'position' => (int) $this->team->galleryFolders()->max('position') + 1,
        ]);

        $this->newFolderName = '';
        $this->refresh();
    }

    public function startRename(int $id): void
    {
        $this->renamingId = $id;
        $this->renameValue = $this->ownFolder($id)->name;
    }

    public function saveRename(): void
    {
        $this->validate(['renameValue' => 'required|string|max:60']);
        $this->ownFolder($this->renamingId)->update(['name' => trim($this->renameValue)]);
        $this->renamingId = null;
        $this->refresh();
    }

    public function moveFolder(int $id, int $direction): void
    {
        $folders = $this->team->galleryFolders()->get()->values();
        $index = $folders->search(fn ($f) => $f->id === $id);
        $swap = $index + $direction;

        if ($index === false || $swap < 0 || $swap >= $folders->count()) {
            return;
        }

        $ordered = $folders->all();
        [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];

        foreach ($ordered as $pos => $folder) {
            $folder->update(['position' => $pos + 1]);
        }

        $this->refresh();
    }

    public function deleteFolder(int $id): void
    {
        $folder = $this->ownFolder($id);
        // Galleries are kept; they just become unfiled.
        $folder->galleries()->update(['gallery_folder_id' => null]);
        $folder->delete();
        $this->refresh();
    }

    public function setFolder(int $galleryId, ?string $folderId): void
    {
        $gallery = $this->team->galleries()->whereKey($galleryId)->firstOrFail();
        $this->authorize('update', $gallery);

        $folder = $folderId ? $this->ownFolder((int) $folderId) : null;
        $gallery->update(['gallery_folder_id' => $folder?->id]);
        $this->refresh();
    }
};
?>

<div>
    <div class="max-lg:hidden">
        <x-ts-button :href="route('galleries')" icon="chevron-left" variant="subtle" inset>{{ __('Galleries') }}</x-ts-button>
    </div>

    <div class="mt-4 lg:mt-8">
        <x-ts-heading level="1" size="xl">{{ __('Folders') }}</x-ts-heading>
        <x-ts-subheading class="mt-2">{{ __('Organize your galleries into folders like Concerts, Events and Weddings. Folders are only for you; clients never see them.') }}</x-ts-subheading>
    </div>

    <div class="mt-8 grid gap-8 lg:grid-cols-[18rem_1fr]">
        <div>
            <form wire:submit="createFolder" class="flex gap-2">
                <div class="flex-1"><x-ts-input wire:model="newFolderName" :placeholder="__('New folder name')" /></div>
                <x-ts-button type="submit" variant="primary" class="btn-ink">{{ __('Add') }}</x-ts-button>
            </form>

            <div class="mt-4 space-y-1 text-sm">
                @forelse ($this->folders as $folder)
                    <div wire:key="folder-{{ $folder->id }}" class="group flex items-center gap-1 rounded-md px-1 hover:bg-zinc-50 dark:hover:bg-white/5">
                        @if ($renamingId === $folder->id)
                            <form wire:submit="saveRename" class="flex flex-1 gap-1 py-1">
                                <div class="flex-1"><x-ts-input wire:model="renameValue" /></div>
                                <x-ts-button type="submit" size="sm" variant="primary" class="btn-ink">{{ __('Save') }}</x-ts-button>
                            </form>
                        @else
                            <a href="{{ route('galleries', ['folder' => $folder->id]) }}" class="flex flex-1 justify-between px-2 py-2">
                                <span class="truncate">&#128193; {{ $folder->name }}</span><span class="text-zinc-500">{{ $folder->galleries_count }}</span>
                            </a>
                            <div class="flex shrink-0 items-center opacity-60 group-hover:opacity-100">
                                <button type="button" title="{{ __('Move up') }}" wire:click="moveFolder({{ $folder->id }}, -1)" class="px-1">&uarr;</button>
                                <button type="button" title="{{ __('Move down') }}" wire:click="moveFolder({{ $folder->id }}, 1)" class="px-1">&darr;</button>
                                <button type="button" title="{{ __('Rename') }}" wire:click="startRename({{ $folder->id }})" class="px-1">&#9998;</button>
                                <button type="button" title="{{ __('Delete folder') }}" wire:click="deleteFolder({{ $folder->id }})"
                                    wire:confirm="{{ __('Delete this folder? Its galleries are kept and become unfiled.') }}" class="px-1 text-red-600">&times;</button>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="px-2 text-zinc-500">{{ __('No folders yet. Try Concerts, Events, Weddings.') }}</p>
                @endforelse
            </div>
        </div>

        <div>
            <x-ts-heading size="lg" level="2">{{ __('Galleries') }}</x-ts-heading>
            <div class="mt-3 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10">
                @forelse ($this->galleries as $gallery)
                    <div wire:key="g-{{ $gallery->id }}" class="flex items-center justify-between gap-4 px-4 py-3">
                        <a href="{{ route('galleries.show', $gallery) }}" class="min-w-0 truncate font-medium hover:underline">{{ $gallery->name }}</a>
                        <select wire:change="setFolder({{ $gallery->id }}, $event.target.value)"
                            class="w-48 shrink-0 rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-white/20 dark:bg-zinc-800">
                            <option value="" @selected(! $gallery->gallery_folder_id)>{{ __('Unfiled') }}</option>
                            @foreach ($this->folders as $folder)
                                <option value="{{ $folder->id }}" @selected($gallery->gallery_folder_id === $folder->id)>{{ $folder->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-zinc-500">{{ __('No galleries yet.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

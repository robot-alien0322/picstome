<?php

// Eugene fork: one click prepares the client ZIPs (full size + Web & Social) ahead of time.

use App\Models\Gallery;
use App\Services\PrepareDownloadsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Gallery $gallery;

    public function mount(): void
    {
        Gate::authorize('update', $this->gallery);
    }

    #[Computed]
    public function rows(): array
    {
        return app(PrepareDownloadsService::class)->status($this->gallery);
    }

    public function prepare(): void
    {
        Gate::authorize('update', $this->gallery);

        app(PrepareDownloadsService::class)->prepare($this->gallery, Auth::user());

        unset($this->rows);
    }
};
?>

@php
    $rows = $this->rows;
    $preparing = collect($rows)->contains('status', 'preparing');
    $allReady = collect($rows)->every(fn ($r) => $r['status'] === 'ready');
@endphp

<div @if ($preparing) wire:poll.5s @endif class="mb-6 rounded-lg border border-zinc-800/10 p-4 dark:border-white/15" data-prepare-downloads>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ts-heading size="sm" level="3">{{ __('Client downloads') }}</x-ts-heading>
            <p class="mt-1 text-sm text-zinc-500 dark:text-white/60">
                {{ __('ZIPs your clients download. Shared galleries prepare them on their own about two hours after uploads stop; this does it now.') }}
            </p>
        </div>
        <x-ts-button wire:click="prepare" sm :disabled="$allReady || $preparing" icon="arrow-path" position="left">
            {{ $allReady ? __('Ready') : ($preparing ? __('Preparing...') : __('Prepare downloads')) }}
        </x-ts-button>
    </div>

    <div class="mt-3 divide-y divide-zinc-800/10 text-sm dark:divide-white/10">
        @foreach ($rows as $row)
            <div wire:key="prep-{{ $row['variant'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                <span class="font-medium">{{ $row['label'] }}</span>
                <span @class([
                    'rounded px-2 py-0.5 text-xs font-medium',
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $row['status'] === 'ready',
                    'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200' => $row['status'] === 'preparing',
                    'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200' => $row['status'] === 'failed',
                    'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-300' => $row['status'] === 'missing',
                ])>
                    @switch($row['status'])
                        @case('ready')
                            {{ __('Ready') }} · {{ Number::fileSize((int) $row['size'], precision: 1) }}@if ($row['expires_at']) · {{ __('kept until :d', ['d' => $row['expires_at']->timezone(config('fork.display_timezone'))->format('M j')]) }}@endif
                            @break
                        @case('preparing')
                            {{ __('Preparing') }}@if ($row['total'] > 0) · {{ $row['processed'] }}/{{ $row['total'] }}@endif
                            @break
                        @case('failed')
                            {{ __('Failed - try again') }}
                            @break
                        @default
                            {{ __('Not prepared') }}
                    @endswitch
                </span>
            </div>
        @endforeach
    </div>
</div>

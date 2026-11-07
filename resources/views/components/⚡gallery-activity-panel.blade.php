<?php

// Eugene fork: gallery activity tab (visits, unique visitors, emails, downloads).

use App\Models\Gallery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Gallery $gallery;

    public bool $requireEmail = true;

    public int $limit = 25;

    public function mount(): void
    {
        Gate::authorize('update', $this->gallery);

        $this->requireEmail = (bool) $this->gallery->require_download_email;
    }

    public function updatedRequireEmail(bool $value): void
    {
        Gate::authorize('update', $this->gallery);

        $this->gallery->forceFill(['require_download_email' => $value])->saveQuietly();
    }

    #[Computed]
    public function stats(): array
    {
        $q = fn () => DB::table('gallery_activities')->where('gallery_id', $this->gallery->id);

        return [
            'visits' => $q()->where('type', 'visit')->count(),
            'unique' => $q()->where('type', 'visit')->distinct()->count('visitor_id'),
            'downloads' => $q()->where('type', 'download')->count(),
            'emails' => $q()->whereNotNull('email')->distinct()->count('email'),
            'visits7' => $q()->where('type', 'visit')->where('created_at', '>=', now()->subDays(7))->count(),
            'lastVisit' => $q()->where('type', 'visit')->max('created_at'),
        ];
    }

    #[Computed]
    public function emails()
    {
        return DB::table('gallery_activities')
            ->where('gallery_id', $this->gallery->id)
            ->whereNotNull('email')
            ->selectRaw("email, max(name) as name, min(created_at) as first_seen, max(created_at) as last_seen, sum(case when type = 'download' then 1 else 0 end) as downloads")
            ->groupBy('email')
            ->orderByDesc('last_seen')
            ->get();
    }

    #[Computed]
    public function recent()
    {
        return $this->gallery->activities()->latest('id')->limit($this->limit)->get();
    }

    public function showMore(): void
    {
        $this->limit += 50;
        unset($this->recent);
    }

    public function refresh(): void
    {
        unset($this->stats, $this->emails, $this->recent);
    }
}; ?>

<div class="space-y-8 py-6" data-gallery-activity>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['Visits', $this->stats['visits'], __(':n in the last 7 days', ['n' => $this->stats['visits7']])],
            ['Unique visitors', $this->stats['unique'], null],
            ['Downloads', $this->stats['downloads'], null],
            ['Emails', $this->stats['emails'], null],
        ] as [$label, $value, $sub])
            <div class="rounded-lg border border-zinc-800/10 p-4 dark:border-white/15">
                <div class="text-xs text-zinc-500">{{ __($label) }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($value) }}</div>
                @if ($sub)<div class="mt-1 text-xs text-zinc-500">{{ $sub }}</div>@endif
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-ts-switch wire:model.live="requireEmail" :label="__('Require an email to download')" />
        <div class="flex items-center gap-3 text-xs text-zinc-500">
            @if ($this->stats['lastVisit'])
                <span>{{ __('Last visit :t', ['t' => \Illuminate\Support\Carbon::parse($this->stats['lastVisit'])->diffForHumans()]) }}</span>
            @endif
            <button type="button" wire:click="refresh" class="hover:underline">{{ __('Refresh') }}</button>
        </div>
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between">
            <x-ts-heading size="sm" level="3">{{ __('Emails') }}</x-ts-heading>
            @if ($this->emails->isNotEmpty())
                <a href="{{ route('galleries.activity.emails', $gallery) }}" class="text-sm text-zinc-500 hover:underline">{{ __('Export CSV') }}</a>
            @endif
        </div>
        @if ($this->emails->isEmpty())
            <p class="text-sm text-zinc-500">{{ __('No one has entered an email yet.') }}</p>
        @else
            <div class="divide-y divide-zinc-800/10 rounded-lg border border-zinc-800/10 text-sm dark:divide-white/10 dark:border-white/15">
                @foreach ($this->emails as $row)
                    <div wire:key="em-{{ md5($row->email) }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                        <div class="min-w-0">
                            <a href="mailto:{{ $row->email }}" class="font-medium hover:underline">{{ $row->email }}</a>
                            @if ($row->name)<span class="text-zinc-500"> · {{ $row->name }}</span>@endif
                        </div>
                        <div class="text-xs text-zinc-500">
                            {{ trans_choice(':n download|:n downloads', (int) $row->downloads, ['n' => (int) $row->downloads]) }}
                            · {{ __('first :t', ['t' => \Illuminate\Support\Carbon::parse($row->first_seen)->format('M j, Y g:ia')]) }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <x-ts-heading size="sm" level="3" class="mb-2">{{ __('Recent activity') }}</x-ts-heading>
        @if ($this->recent->isEmpty())
            <p class="text-sm text-zinc-500">{{ __('No visits yet. Visits from you while logged in are not counted.') }}</p>
        @else
            <div class="divide-y divide-zinc-800/10 rounded-lg border border-zinc-800/10 text-sm dark:divide-white/10 dark:border-white/15">
                @foreach ($this->recent as $a)
                    <div wire:key="act-{{ $a->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2">
                        <span class="w-36 shrink-0 text-xs text-zinc-500" title="{{ $a->created_at }}">{{ $a->created_at->format('M j, g:ia') }}</span>
                        <span @class([
                            'rounded px-1.5 py-0.5 text-xs font-medium',
                            'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-300' => $a->type === 'visit',
                            'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200' => $a->type === 'email',
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $a->type === 'download',
                        ])>{{ ['visit' => __('Visit'), 'email' => __('Email'), 'download' => __('Download')][$a->type] ?? $a->type }}</span>
                        <span class="min-w-0 truncate">{{ $a->email ?? __('Visitor :id', ['id' => substr((string) $a->visitor_id, 0, 6)]) }}</span>
                        @if ($a->detail)<span class="truncate text-zinc-500">{{ $a->detail }}</span>@endif
                        @if ($a->country)<span class="ml-auto text-xs text-zinc-400">{{ $a->country }}</span>@endif
                    </div>
                @endforeach
            </div>
            @if ($this->recent->count() >= $limit)
                <button type="button" wire:click="showMore" class="mt-2 text-sm text-zinc-500 hover:underline">{{ __('Show more') }}</button>
            @endif
        @endif
    </div>
</div>

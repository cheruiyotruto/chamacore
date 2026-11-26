@extends('layouts.app')

@section('title', 'Merry-go-round')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canManage = $ctx->hasPermission('mgr.manage');
    @endphp

    <x-page-header title="Merry-go-round" :subtitle="'Rotating savings cycles · '.($currentGroup?->name ?? 'this group')">
        @if ($canManage)
            <a href="{{ route('mgr.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> New cycle
            </a>
        @endif
    </x-page-header>

    <x-card :padding="false">
        <form method="GET" action="{{ route('mgr.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <div class="w-full sm:w-56">
                <x-select name="status" label="Status" placeholder="All statuses" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s => ucfirst($s)])->all()" :value="request('status')" />
            </div>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="search" class="h-4 w-4" /> Filter
            </button>
            <a href="{{ route('mgr.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
        </form>

        @if ($cycles->isEmpty())
            <x-empty title="No merry-go-round cycles" message="Create a cycle to start rotating contributions between members." class="m-4">
                @if ($canManage)
                    <a href="{{ route('mgr.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="plus" class="h-4 w-4" /> Create the first cycle
                    </a>
                @endif
            </x-empty>
        @else
            <div class="grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($cycles as $cycle)
                    @php
                        $paidRatio = $cycle->paid_contributions_count + $cycle->pending_contributions_count > 0
                            ? (int) round(($cycle->paid_contributions_count / max(1, $cycle->paid_contributions_count + $cycle->pending_contributions_count)) * 100)
                            : 0;
                    @endphp
                    <a href="{{ route('mgr.show', $cycle) }}" class="group block rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-300 hover:shadow">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900 group-hover:text-brand-700">{{ $cycle->name }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ $cycle->frequency === 'weekly' ? 'Weekly' : 'Monthly' }} · {{ ucfirst($cycle->rotation_type) }} rotation</p>
                            </div>
                            <x-badge :status="$cycle->status" />
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-xs text-slate-500">Contribution</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900">@money($cycle->contribution_amount)</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Pool target</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900">@money($cycle->expectedPool())</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Participants</dt>
                                <dd class="mt-0.5 font-medium text-slate-800">{{ $cycle->participants_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Dates</dt>
                                <dd class="mt-0.5 font-medium text-slate-800">{{ $cycle->start_date?->format('d M') }} – {{ $cycle->end_date?->format('d M Y') }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4">
                            <div class="flex items-center justify-between text-xs text-slate-500">
                                <span>{{ $cycle->paid_contributions_count }} paid · {{ $cycle->pending_contributions_count }} outstanding</span>
                                <span>{{ $paidRatio }}%</span>
                            </div>
                            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-brand-500" style="width: {{ $paidRatio }}%"></div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $cycles->links() }}</div>
        @endif
    </x-card>
@endsection

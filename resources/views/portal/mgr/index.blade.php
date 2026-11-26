@extends('layouts.portal')

@section('title', 'My merry-go-round')

@section('content')
    @php
        $rows = $participations->getCollection();
        $contributed = (int) $rows->sum('total_contributed');
        $received = (int) $rows->sum('total_received');
        $nextPayout = $rows->where('payout_date', '!=', null)->sortBy('payout_date')->first();
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">My merry-go-round</h1>
        <p class="mt-1 text-sm text-slate-500">Your rotation position, contributions and payouts across group cycles.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Participations" :value="number_format($participations->total())" :hint="$rows->where('status', 'active')->count().' active on this page'" />
        <x-stat label="Contributed" :value="\App\Support\Money::format($contributed)" hint="Across the listed cycles" tone="info" />
        <x-stat label="Received" :value="\App\Support\Money::format($received)" hint="Payouts paid to you" tone="brand" />
        <x-stat label="Next payout" :value="$nextPayout?->payout_date?->format('d M Y') ?? '—'" :hint="$nextPayout?->cycle?->name ?? 'No payout scheduled'" tone="neutral" />
    </div>

    <div class="mt-6">
        <x-card title="My cycles" :subtitle="$participations->total().' participation(s)'" :padding="false">
            @if ($participations->isEmpty())
                <x-empty title="Not in a merry-go-round" message="Ask the group office to add you to an active cycle." class="m-4" />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($participations as $participation)
                        @php $cycle = $participation->cycle; @endphp
                        <a href="{{ route('portal.mgr.show', $cycle) }}" class="block px-4 py-4 hover:bg-slate-50">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900">{{ $cycle?->name ?? 'Merry-go-round' }}</p>
                                    <p class="text-xs text-slate-500">
                                        @if ($cycle)
                                            {{ ucfirst($cycle->frequency) }} contribution of @money($cycle->contribution_amount)
                                            · Position {{ $participation->position }}
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        @if ($cycle?->start_date)
                                            {{ $cycle->start_date->format('d M Y') }} – {{ $cycle->end_date?->format('d M Y') ?? 'ongoing' }}
                                        @endif
                                        @if ($participation->payout_date)
                                            · Payout date {{ $participation->payout_date->format('d M Y') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-slate-900">@money($participation->total_contributed)</p>
                                    <p class="text-xs text-slate-500">contributed</p>
                                    <p class="mt-1 text-xs text-slate-500">received @money($participation->total_received)</p>
                                    <div class="mt-1 flex flex-wrap items-center justify-end gap-1.5">
                                        <x-badge :status="$participation->status" />
                                        @if ($participation->payout)
                                            <x-badge :status="$participation->payout->status" :label="'Payout '.$participation->payout->status" />
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
                <div class="border-t border-slate-200 px-4 py-3">{{ $participations->links() }}</div>
            @endif
        </x-card>
    </div>
@endsection

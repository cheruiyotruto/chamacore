@extends('layouts.app')

@section('title', 'Merry-go-round report')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canExport = $ctx->hasPermission('reports.export');
        $cycles = $report['cycles'];
        $activeCycles = $cycles->whereIn('status', ['active', 'open', 'in_progress'])->count();
    @endphp

    <x-page-header title="Merry-go-round" :subtitle="'Cycle performance · '.($currentGroup?->name ?? 'this group')">
        <a href="{{ route('reports.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All reports
        </a>
        @if ($canExport)
            <a href="{{ route('reports.export', ['type' => 'mgr']) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4 w-4" /> Export CSV
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Collected" :value="\App\Support\Money::format($report['collected'])" hint="Paid contributions" />
        <x-stat label="Pending" :value="\App\Support\Money::format($report['pending'])" hint="Pending, late or missed" tone="warning" />
        <x-stat label="Payouts" :value="\App\Support\Money::format($report['payouts'])" hint="Paid to members" tone="info" />
        <x-stat label="Cycles" :value="number_format($cycles->count())" :hint="$activeCycles.' active'" tone="neutral" />
    </div>

    <x-card :padding="false">
        @if ($cycles->isEmpty())
            <x-empty title="No cycles" message="Create a merry-go-round cycle to start tracking contributions." class="m-4" />
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($cycles as $cycle)
                    @php
                        $participantCount = $cycle->participants->count();
                        $paidContributions = $cycle->contributions->where('status', 'paid')->count();
                        $cyclePayouts = $cycle->payouts->where('status', 'paid')->sum('amount');
                        $pool = $cycle->expectedPool();
                    @endphp
                    <div class="p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('mgr.show', $cycle) }}" class="text-sm font-semibold text-slate-900 hover:text-brand-700">{{ $cycle->name }}</a>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ ucfirst($cycle->frequency) }} · {{ \App\Support\Money::format($cycle->contribution_amount) }} per member ·
                                    {{ $cycle->start_date?->format('d M Y') ?? '—' }} to {{ $cycle->end_date?->format('d M Y') ?? '—' }}
                                </p>
                            </div>
                            <x-badge :status="$cycle->status" />
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-4">
                            <div>
                                <p class="text-xs text-slate-500">Participants</p>
                                <p class="text-sm font-semibold text-slate-900">{{ $participantCount }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Expected pool</p>
                                <p class="text-sm font-semibold text-slate-900">@money($pool)</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Paid contributions</p>
                                <p class="text-sm font-semibold text-slate-900">{{ $paidContributions }} <span class="font-normal text-slate-500">of {{ $cycle->contributions->count() }}</span></p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Payouts paid</p>
                                <p class="text-sm font-semibold text-slate-900">@money($cyclePayouts)</p>
                            </div>
                        </div>

                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ $cycle->contributions->count() > 0 ? max(2, (int) round(($paidContributions / $cycle->contributions->count()) * 100)) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>
@endsection

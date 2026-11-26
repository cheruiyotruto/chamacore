@extends('layouts.app')

@section('title', 'Balance sheet')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $assets = collect($report['assets']);
        $liabilities = collect($report['liabilities']);
        $equity = collect($report['equity']);
        $balanced = (bool) ($report['balanced'] ?? false);
        $funded = ($report['total_liabilities'] ?? 0) + ($report['total_equity'] ?? 0) + ($report['net_income'] ?? 0);
    @endphp

    <x-page-header title="Balance sheet" :subtitle="'Financial position as at '.$to">
        <a href="{{ route('reports.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All reports
        </a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total assets" :value="\App\Support\Money::format($report['total_assets'] ?? 0)" hint="Asset accounts" />
        <x-stat label="Liabilities" :value="\App\Support\Money::format($report['total_liabilities'] ?? 0)" hint="Amounts owed" tone="warning" />
        <x-stat label="Equity" :value="\App\Support\Money::format(($report['total_equity'] ?? 0) + ($report['net_income'] ?? 0))" hint="Capital plus net income" tone="info" />
        <x-stat label="Check" :value="$balanced ? 'Balanced' : 'Out by '.\App\Support\Money::format(abs(($report['total_assets'] ?? 0) - $funded))" :hint="$balanced ? 'Assets equal liabilities and equity' : 'Review the ledger'" :tone="$balanced ? 'brand' : 'danger'" />
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('reports.balance-sheet') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <div class="w-full sm:w-48">
                <x-input name="to" type="date" label="As at" :value="$to" />
            </div>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="search" class="h-4 w-4" /> Apply
            </button>
            <a href="{{ route('reports.balance-sheet') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
        </form>

        <div class="grid gap-5 p-4 lg:grid-cols-2">
            <div class="space-y-5">
                <div class="overflow-hidden rounded-xl border border-slate-200">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3">
                        <h3 class="text-sm font-semibold text-slate-900">Assets</h3>
                        <span class="text-sm font-semibold text-slate-900">@money($report['total_assets'] ?? 0)</span>
                    </div>
                    @if ($assets->isEmpty())
                        <p class="px-4 py-6 text-center text-sm text-slate-500">No asset balances.</p>
                    @else
                        <div class="divide-y divide-slate-100">
                            @foreach ($assets as $row)
                                <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <div class="min-w-0">
                                        <a href="{{ route('accounting.ledger', ['account' => $row['account']->code, 'to' => $to]) }}" class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ $row['account']->name }}</a>
                                        <p class="font-mono text-xs text-slate-400">{{ $row['account']->code }}</p>
                                    </div>
                                    <span class="text-sm text-slate-700">@money($row['amount'])</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="space-y-5">
                <div class="overflow-hidden rounded-xl border border-slate-200">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3">
                        <h3 class="text-sm font-semibold text-slate-900">Liabilities</h3>
                        <span class="text-sm font-semibold text-slate-900">@money($report['total_liabilities'] ?? 0)</span>
                    </div>
                    @if ($liabilities->isEmpty())
                        <p class="px-4 py-6 text-center text-sm text-slate-500">No liability balances.</p>
                    @else
                        <div class="divide-y divide-slate-100">
                            @foreach ($liabilities as $row)
                                <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <div class="min-w-0">
                                        <a href="{{ route('accounting.ledger', ['account' => $row['account']->code, 'to' => $to]) }}" class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ $row['account']->name }}</a>
                                        <p class="font-mono text-xs text-slate-400">{{ $row['account']->code }}</p>
                                    </div>
                                    <span class="text-sm text-slate-700">@money($row['amount'])</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="overflow-hidden rounded-xl border border-slate-200">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3">
                        <h3 class="text-sm font-semibold text-slate-900">Equity</h3>
                        <span class="text-sm font-semibold text-slate-900">@money($report['total_equity'] ?? 0)</span>
                    </div>
                    @if ($equity->isEmpty())
                        <p class="px-4 py-6 text-center text-sm text-slate-500">No equity balances.</p>
                    @else
                        <div class="divide-y divide-slate-100">
                            @foreach ($equity as $row)
                                <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <div class="min-w-0">
                                        <a href="{{ route('accounting.ledger', ['account' => $row['account']->code, 'to' => $to]) }}" class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ $row['account']->name }}</a>
                                        <p class="font-mono text-xs text-slate-400">{{ $row['account']->code }}</p>
                                    </div>
                                    <span class="text-sm text-slate-700">@money($row['amount'])</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-2.5">
                        <span class="text-sm text-slate-600">Net income for the year</span>
                        <span class="text-sm font-medium text-slate-800">@money($report['net_income'] ?? 0)</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Liabilities + equity + net income</span>
            <span class="text-sm font-semibold text-slate-900">@money($funded)</span>
        </div>
    </x-card>
@endsection

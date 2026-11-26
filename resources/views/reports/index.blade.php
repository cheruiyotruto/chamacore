@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canExport = $ctx->hasPermission('reports.export');

        $reports = [
            [
                'title' => 'Trial balance',
                'description' => 'Debit and credit totals for every ledger account over a chosen period.',
                'route' => 'reports.trial-balance',
                'icon' => 'scale',
                'type' => 'trial_balance',
                'period' => true,
            ],
            [
                'title' => 'Income statement',
                'description' => 'Income and expenses with the net surplus or deficit for a period.',
                'route' => 'reports.income-statement',
                'icon' => 'chart',
                'type' => 'income_statement',
                'period' => true,
            ],
            [
                'title' => 'Balance sheet',
                'description' => 'Assets, liabilities and equity as at a selected date.',
                'route' => 'reports.balance-sheet',
                'icon' => 'book',
                'type' => null,
                'period' => false,
            ],
            [
                'title' => 'Savings summary',
                'description' => 'Member savings balances, pledges and totals by product.',
                'route' => 'reports.savings',
                'icon' => 'banknotes',
                'type' => 'savings',
                'period' => false,
            ],
            [
                'title' => 'Loan portfolio',
                'description' => 'Active principal, outstanding balances, interest, penalties and arrears.',
                'route' => 'reports.loans',
                'icon' => 'credit-card',
                'type' => 'loans',
                'period' => false,
            ],
            [
                'title' => 'Merry-go-round',
                'description' => 'Cycle performance, contributions collected, pending amounts and payouts.',
                'route' => 'reports.mgr',
                'icon' => 'calendar',
                'type' => 'mgr',
                'period' => false,
            ],
            [
                'title' => 'Member statement',
                'description' => 'Consolidated savings, wallet and loan activity for one member.',
                'route' => 'reports.member-statement',
                'icon' => 'users',
                'type' => 'member_statement',
                'period' => true,
            ],
        ];
    @endphp

    <x-page-header title="Reports" :subtitle="'Financial and operational reporting · '.($currentGroup?->name ?? 'this group')">
        @if ($canExport)
            <span class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-600">
                <x-icon name="download" class="h-4 w-4" /> CSV export on each report
            </span>
        @endif
    </x-page-header>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($reports as $report)
            <x-card>
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                        <x-icon :name="$report['icon']" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $report['title'] }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ $report['description'] }}</p>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
                    <a href="{{ route($report['route']) }}" class="text-sm font-semibold text-brand-700 hover:text-brand-800">Open report</a>
                    @if ($canExport && $report['type'])
                        <form method="GET" action="{{ route('reports.export') }}" class="flex items-center gap-2">
                            <input type="hidden" name="type" value="{{ $report['type'] }}">
                            @if ($report['period'])
                                <input type="hidden" name="from" value="{{ now()->startOfYear()->toDateString() }}">
                                <input type="hidden" name="to" value="{{ now()->toDateString() }}">
                            @endif
                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                                <x-icon name="download" class="h-3.5 w-3.5" /> CSV
                            </button>
                        </form>
                    @endif
                </div>
            </x-card>
        @endforeach
    </div>

    <x-card title="How reports are produced" subtitle="Read-only derivations from posted journals and stored balances.">
        <div class="grid gap-4 text-sm text-slate-600 sm:grid-cols-2">
            <p>Financial statements are derived from the general ledger only. Nothing on this page mutates financial state.</p>
            <p>Period defaults to the start of the current year through today. Adjust the filters on each report to change the window.</p>
        </div>
    </x-card>
@endsection

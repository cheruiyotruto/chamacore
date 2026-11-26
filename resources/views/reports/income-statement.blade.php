@extends('layouts.app')

@section('title', 'Income statement')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canExport = $ctx->hasPermission('reports.export');
        $income = collect($report['income']);
        $expense = collect($report['expense']);
        $netPositive = ($report['net'] ?? 0) >= 0;
    @endphp

    <x-page-header title="Income statement" :subtitle="'Period '.$from.' to '.$to">
        <a href="{{ route('reports.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All reports
        </a>
        @if ($canExport)
            <a href="{{ route('reports.export', ['type' => 'income_statement', 'from' => $from, 'to' => $to]) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4 w-4" /> Export CSV
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total income" :value="\App\Support\Money::format($report['total_income'] ?? 0)" hint="Revenue accounts" tone="info" />
        <x-stat label="Total expense" :value="\App\Support\Money::format($report['total_expense'] ?? 0)" hint="Expense accounts" tone="warning" />
        <x-stat label="Net {{ $netPositive ? 'surplus' : 'deficit' }}" :value="\App\Support\Money::format(abs($report['net'] ?? 0))" :hint="$netPositive ? 'Income exceeds expenses' : 'Expenses exceed income'" :tone="$netPositive ? 'brand' : 'danger'" />
        <x-stat label="Period" :value="$from.' – '.$to" hint="As reported" tone="neutral" />
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        <x-card title="Income" :subtitle="$income->count().' account(s)'" :padding="false">
            @if ($income->isEmpty())
                <x-empty title="No income" message="No income posted for this period." class="m-4" />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($income as $row)
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <div class="min-w-0">
                                <a href="{{ route('accounting.ledger', ['account' => $row['account']->code, 'from' => $from, 'to' => $to]) }}" class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ $row['account']->name }}</a>
                                <p class="font-mono text-xs text-slate-400">{{ $row['account']->code }}</p>
                            </div>
                            <span class="text-sm font-semibold text-slate-900">@money($row['amount'])</span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between gap-3 bg-slate-50 px-4 py-3">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total income</span>
                        <span class="text-sm font-semibold text-slate-900">@money($report['total_income'] ?? 0)</span>
                    </div>
                </div>
            @endif
        </x-card>

        <x-card title="Expenses" :subtitle="$expense->count().' account(s)'" :padding="false">
            @if ($expense->isEmpty())
                <x-empty title="No expenses" message="No expenses posted for this period." class="m-4" />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($expense as $row)
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <div class="min-w-0">
                                <a href="{{ route('accounting.ledger', ['account' => $row['account']->code, 'from' => $from, 'to' => $to]) }}" class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ $row['account']->name }}</a>
                                <p class="font-mono text-xs text-slate-400">{{ $row['account']->code }}</p>
                            </div>
                            <span class="text-sm font-semibold text-slate-900">@money($row['amount'])</span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between gap-3 bg-slate-50 px-4 py-3">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total expense</span>
                        <span class="text-sm font-semibold text-slate-900">@money($report['total_expense'] ?? 0)</span>
                    </div>
                </div>
            @endif
        </x-card>
    </div>

    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-slate-900">Net {{ $netPositive ? 'surplus' : 'deficit' }} for the period</p>
                <p class="text-xs text-slate-500">Total income − total expenses</p>
            </div>
            <p class="text-lg font-semibold {{ $netPositive ? 'text-emerald-700' : 'text-rose-700' }}">@money($report['net'] ?? 0)</p>
        </div>
    </x-card>
@endsection

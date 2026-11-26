@extends('layouts.app')

@section('title', 'Loan portfolio')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canExport = $ctx->hasPermission('reports.export');
        $loans = $report['loans'];
        $byStatus = $report['by_status'] ?? [];
        ksort($byStatus);
        $activeOutstanding = (int) $report['outstanding'];
        $principal = (int) $report['principal'];
    @endphp

    <x-page-header title="Loan portfolio" :subtitle="'Latest position across all loan products · '.($currentGroup?->name ?? 'this group')">
        <a href="{{ route('reports.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All reports
        </a>
        @if ($canExport)
            <a href="{{ route('reports.export', ['type' => 'loans']) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4 w-4" /> Export CSV
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Active principal" :value="\App\Support\Money::format($principal)" hint="Disbursed and running" />
        <x-stat label="Outstanding" :value="\App\Support\Money::format($activeOutstanding)" hint="Principal, interest and penalties" tone="info" />
        <x-stat label="Arrears" :value="\App\Support\Money::format($report['arrears'])" hint="Overdue across all loans" tone="danger" />
        <x-stat label="Loans" :value="number_format($loans->count())" hint="All time" tone="neutral" />
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Outstanding interest" :value="\App\Support\Money::format($report['interest'])" hint="Active loans" tone="warning" />
        <x-stat label="Outstanding penalties" :value="\App\Support\Money::format($report['penalties'])" hint="Active loans" tone="warning" />
        <x-stat label="Repaid to date" :value="\App\Support\Money::format(max(0, $principal + $report['interest'] - $activeOutstanding))" hint="Principal and interest recovered" tone="brand" />
    </div>

    <div class="grid gap-5 xl:grid-cols-4">
        <div class="xl:col-span-3">
            <x-card title="Loans" :subtitle="$loans->count().' loan(s)'" :padding="false">
                @if ($loans->isEmpty())
                    <x-empty title="No loans" message="Loan applications and disbursements will appear here." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Loan</th>
                                    <th class="px-4 py-3">Member</th>
                                    <th class="px-4 py-3">Product</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Principal</th>
                                    <th class="px-4 py-3 text-right">Outstanding</th>
                                    <th class="px-4 py-3 text-right">Arrears</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($loans as $loan)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('loans.show', $loan) }}" class="font-mono text-xs font-medium text-brand-700 hover:text-brand-800">{{ $loan->loan_no }}</a>
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('members.show', $loan->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $loan->member?->full_name ?? '—' }}</a>
                                            <p class="text-xs text-slate-400">{{ $loan->member?->member_no }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $loan->product?->name ?? '—' }}</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$loan->status" :label="$loan->statusLabel()" /></td>
                                        <td class="px-4 py-2.5 text-right text-slate-700">@money($loan->principal)</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">@money($loan->outstandingTotal())</td>
                                        <td class="px-4 py-2.5 text-right {{ $loan->overdueAmount() > 0 ? 'font-semibold text-rose-700' : 'text-slate-500' }}">@money($loan->overdueAmount())</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="By status" :padding="false">
                @if (empty($byStatus))
                    <x-empty title="Nothing to show" class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($byStatus as $status => $summary)
                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <div>
                                    <x-badge :status="$status" />
                                    <p class="mt-1 text-xs text-slate-400">{{ $summary['count'] }} loan(s)</p>
                                </div>
                                <span class="text-sm font-semibold text-slate-900">@money($summary['outstanding'])</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Portfolio links">
                <a href="{{ route('loans.index') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="credit-card" class="h-4 w-4" /> Open loans
                </a>
                <a href="{{ route('reports.member-statement') }}" class="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="users" class="h-4 w-4" /> Member statement
                </a>
            </x-card>
        </div>
    </div>
@endsection

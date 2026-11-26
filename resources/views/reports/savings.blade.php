@extends('layouts.app')

@section('title', 'Savings summary')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canExport = $ctx->hasPermission('reports.export');
        $accounts = $report['accounts'];
        $byProduct = $report['by_product'] ?? [];
        arsort($byProduct);
        $maxProduct = max(1, collect($byProduct)->max() ?? 1);
    @endphp

    <x-page-header title="Savings summary" :subtitle="'Active savings accounts · '.($currentGroup?->name ?? 'this group')">
        <a href="{{ route('reports.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All reports
        </a>
        @if ($canExport)
            <a href="{{ route('reports.export', ['type' => 'savings']) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4 w-4" /> Export CSV
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total savings" :value="\App\Support\Money::format($report['total'])" hint="Across active accounts" />
        <x-stat label="Pledged" :value="\App\Support\Money::format($report['pledged'])" hint="Held as loan security" tone="warning" />
        <x-stat label="Accounts" :value="number_format($accounts->count())" hint="Active savings accounts" tone="info" />
        <x-stat label="Products" :value="number_format(count($byProduct))" hint="Savings products in use" tone="neutral" />
    </div>

    @if (! empty($byProduct))
        <x-card title="Balances by product" :padding="false">
            <div class="divide-y divide-slate-100">
                @foreach ($byProduct as $name => $amount)
                    <div class="px-4 py-3">
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="font-medium text-slate-800">{{ $name }}</span>
                            <span class="font-semibold text-slate-900">@money($amount)</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ max(2, (int) round(($amount / $maxProduct) * 100)) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    <x-card title="Accounts" :subtitle="$accounts->count().' active account(s)'" :padding="false">
        @if ($accounts->isEmpty())
            <x-empty title="No savings accounts" message="Active savings accounts will appear here." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3">Account</th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            <th class="px-4 py-3 text-right">Available</th>
                            <th class="px-4 py-3 text-right">Pledged</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($accounts as $account)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('members.show', $account->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $account->member?->full_name ?? '—' }}</a>
                                    <p class="text-xs text-slate-400">{{ $account->member?->member_no }}</p>
                                </td>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('savings.show', $account) }}" class="font-mono text-xs font-medium text-brand-700 hover:text-brand-800">{{ $account->account_no }}</a>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $account->product?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($account->balance)</td>
                                <td class="px-4 py-2.5 text-right text-slate-700">@money($account->available_balance)</td>
                                <td class="px-4 py-2.5 text-right text-slate-700">@money($account->pledged_amount)</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr>
                            <td colspan="3" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Totals</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($report['total'])</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($accounts->sum('available_balance'))</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($report['pledged'])</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-card>
@endsection

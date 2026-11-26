@extends('layouts.app')

@section('title', 'Loans')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
    @endphp

    <x-page-header title="Loans" :subtitle="($loans->total()).' application(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($can('loans.create'))
            <a href="{{ route('loans.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> New loan application
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Active loans" :value="number_format($portfolio['active'] ?? 0)" hint="Currently disbursed/active" />
        <x-stat label="Outstanding" :value="\App\Support\Money::format($portfolio['outstanding'] ?? 0)" hint="Total outstanding across active loans" tone="warning" />
        <x-stat label="Pending review" :value="number_format($portfolio['pending'] ?? 0)" hint="Awaiting approval/guarantors" tone="info" />
        <x-stat label="Loan products" :value="number_format($products->count())" hint="Configured products" tone="neutral" />
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('loans.index') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-input name="search" placeholder="Loan no, member no or name" :value="request('search')" />
            <x-select name="status" placeholder="All statuses" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))])->all()" :value="request('status')" />
            <x-select name="product" placeholder="All products" :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->name])->all()" :value="request('product')" />
            <div class="flex items-end gap-2 lg:col-span-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Filter
                </button>
                <a href="{{ route('loans.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if ($loans->isEmpty())
            <x-empty title="No loans found" message="Create a new application or adjust your filters." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Loan</th>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3 text-right">Requested</th>
                            <th class="px-4 py-3 text-right">Outstanding</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Submitted</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($loans as $loan)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-900">
                                    <a href="{{ route('loans.show', $loan) }}" class="hover:text-brand-700">{{ $loan->loan_no }}</a>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('members.show', $loan->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $loan->member?->full_name ?? '—' }}</a>
                                    <p class="text-xs text-slate-400">{{ $loan->member?->member_no }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $loan->product?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-slate-600">@money($loan->requested_amount)</td>
                                <td class="px-4 py-3 text-right font-medium text-slate-900">@money($loan->outstandingTotal())</td>
                                <td class="px-4 py-3"><x-badge :status="$loan->status" /></td>
                                <td class="px-4 py-3 text-slate-600">{{ $loan->submitted_at?->format('d M Y') ?? $loan->created_at?->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('loans.show', $loan) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $loans->links() }}</div>
        @endif
    </x-card>
@endsection

@extends('layouts.app')

@section('title', 'Wallets')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
    @endphp

    <x-page-header title="Member wallets" :subtitle="$wallets->total().' wallet(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($can('wallet.reconcile'))
            <a href="{{ route('wallet.reconcile') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="scale" class="h-4 w-4" /> Reconcile
            </a>
        @endif
        @if ($can('wallet.adjust'))
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4 w-4" /> Adjust wallet
                </summary>
                <form method="POST" action="{{ route('wallet.adjust') }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Credit or debit a wallet</p>
                    <x-select name="member_id" label="Member" :options="$memberOptions" placeholder="Select a member" required />
                    <x-select name="direction" label="Direction" :options="['credit' => 'Credit (deposit)', 'debit' => 'Debit (withdrawal)']" required />
                    <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                    <x-select name="counter" label="Counter account" :options="['cash' => 'Cash', 'bank' => 'Bank']" required />
                    <x-input name="description" label="Description" placeholder="Reason for this adjustment" required />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Post adjustment</button>
                </form>
            </details>
        @endif
        @if ($can('wallet.transfer'))
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="arrow-right" class="h-4 w-4" /> Transfer
                </summary>
                <form method="POST" action="{{ route('wallet.transfer') }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Transfer between wallets</p>
                    <x-select name="from_member_id" label="From member" :options="$memberOptions" placeholder="Select sender" required />
                    <x-select name="to_member_id" label="To member" :options="$memberOptions" placeholder="Select recipient" required />
                    <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                    <button type="submit" class="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Transfer funds</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total balance" :value="\App\Support\Money::format($totalBalance)" hint="Held in member wallets" />
        <x-stat label="Available" :value="\App\Support\Money::format($totalAvailable)" hint="After holds and pledges" tone="info" />
        <x-stat label="Wallets" :value="number_format($wallets->total())" hint="Member wallets on this page" tone="neutral" />
        <x-stat label="Members" :value="number_format($members->count())" hint="Active members with wallets" tone="neutral" />
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('wallet.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <div class="w-full sm:w-72">
                <x-input name="search" placeholder="Member name, number or phone" :value="request('search')" />
            </div>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="search" class="h-4 w-4" /> Filter
            </button>
            <a href="{{ route('wallet.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
        </form>

        @if ($wallets->isEmpty())
            <x-empty title="No wallets" message="Wallets are created automatically when a member first uses wallet services." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            <th class="px-4 py-3 text-right">Available</th>
                            <th class="px-4 py-3 text-right">Held</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($wallets as $wallet)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('members.show', $wallet->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $wallet->member?->full_name ?? '—' }}</a>
                                    <p class="text-xs text-slate-400">{{ $wallet->member?->member_no }} · {{ $wallet->member?->phone ?: 'no phone' }}</p>
                                </td>
                                <td class="px-4 py-3"><x-badge :status="$wallet->status" /></td>
                                <td class="px-4 py-3 text-right font-medium text-slate-900">@money($wallet->balance)</td>
                                <td class="px-4 py-3 text-right text-slate-600">@money($wallet->available_balance)</td>
                                <td class="px-4 py-3 text-right text-slate-600">@money(max(0, $wallet->balance - $wallet->available_balance))</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('wallet.show', $wallet) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $wallets->links() }}</div>
        @endif
    </x-card>
@endsection

@extends('layouts.app')

@section('title', 'Savings')

@section('content')
    @php
        $can = fn (string $permission) => ($chamaContext ?? app(\App\Support\GroupContext::class))->hasPermission($permission);
        $productOptions = $products->mapWithKeys(fn ($product) => [$product->id => $product->name.' ('.$product->code.')'])->all();
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
    @endphp

    <x-page-header title="Savings" :subtitle="$accounts->total().' account(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($can('savings.deposit'))
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4 w-4" /> Open account
                </summary>
                <form method="POST" action="{{ route('savings.accounts.store') }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Open a savings account</p>
                    <x-select name="member_id" label="Member" :options="$memberOptions" required />
                    <x-select name="savings_product_id" label="Product" :options="$productOptions" required />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Open account</button>
                </form>
            </details>
        @endif
        @if ($can('savings.manage'))
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="cog" class="h-4 w-4" /> New product
                </summary>
                <form method="POST" action="{{ route('savings.products.store') }}" class="absolute right-0 z-20 mt-2 w-96 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Create a savings product</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-input name="name" label="Name" required />
                        <x-input name="code" label="Code" required />
                        <x-select name="type" label="Type" :options="['mandatory' => 'Mandatory', 'voluntary' => 'Voluntary', 'emergency' => 'Emergency', 'shares' => 'Shares', 'welfare' => 'Welfare', 'special' => 'Special']" required />
                        <x-input name="interest_rate" type="number" step="0.01" label="Interest rate (%)" />
                        <x-input name="min_balance" type="number" step="0.01" label="Minimum balance" />
                        <x-input name="contribution_amount" type="number" step="0.01" label="Contribution amount" />
                        <x-select name="contribution_frequency" label="Frequency" :options="['weekly' => 'Weekly', 'monthly' => 'Monthly', 'none' => 'None']" />
                    </div>
                    <div class="flex flex-wrap gap-4 text-sm text-slate-600">
                        <label class="flex items-center gap-2"><input type="checkbox" name="allows_withdrawal" value="1" class="rounded border-slate-300 text-brand-600"> Withdrawals</label>
                        <label class="flex items-center gap-2"><input type="checkbox" name="qualifies_for_loan" value="1" checked class="rounded border-slate-300 text-brand-600"> Qualifies for loans</label>
                        <label class="flex items-center gap-2"><input type="checkbox" name="is_mandatory" value="1" class="rounded border-slate-300 text-brand-600"> Mandatory</label>
                    </div>
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create product</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total savings" :value="\App\Support\Money::format($accountsTotal)" hint="All account balances" />
        <x-stat label="Pledged" :value="\App\Support\Money::format($pledgedTotal)" hint="Locked as loan security" tone="warning" />
        <x-stat label="Accounts" :value="number_format($accounts->total())" :hint="'Across '.$products->count().' product(s)'" tone="info" />
        <x-stat label="Products" :value="number_format($products->count())" hint="Configured savings products" tone="neutral" />
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('savings.index') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-input name="search" placeholder="Account no, member no or name" :value="request('search')" />
            <x-select name="product" placeholder="All products" :options="$productOptions" :value="request('product')" />
            <div class="flex items-end gap-2 lg:col-span-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Filter
                </button>
                <a href="{{ route('savings.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if ($accounts->isEmpty())
            <x-empty title="No savings accounts" message="Open an account or adjust the filters." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Account</th>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            <th class="px-4 py-3 text-right">Available</th>
                            <th class="px-4 py-3 text-right">Pledged</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($accounts as $account)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-900">
                                    <a href="{{ route('savings.show', $account) }}" class="hover:text-brand-700">{{ $account->account_no }}</a>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('members.show', $account->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $account->member?->full_name ?? '—' }}</a>
                                    <p class="text-xs text-slate-400">{{ $account->member?->member_no }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $account->product?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-medium text-slate-900">@money($account->balance)</td>
                                <td class="px-4 py-3 text-right text-slate-600">@money($account->available_balance)</td>
                                <td class="px-4 py-3 text-right text-slate-600">@money($account->pledged_amount)</td>
                                <td class="px-4 py-3"><x-badge :status="$account->status" /></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('savings.show', $account) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $accounts->links() }}</div>
        @endif
    </x-card>

    <x-card title="Savings products" :padding="false">
        @if ($products->isEmpty())
            <x-empty title="No products configured" message="Create a product before opening accounts." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">Product</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5 text-right">Interest</th>
                            <th class="px-4 py-2.5 text-right">Min balance</th>
                            <th class="px-4 py-2.5 text-right">Accounts</th>
                            <th class="px-4 py-2.5">Rules</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($products as $product)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <p class="font-medium text-slate-900">{{ $product->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $product->code }}</p>
                                </td>
                                <td class="px-4 py-2.5"><x-badge :status="$product->type" /></td>
                                <td class="px-4 py-2.5 text-right text-slate-600">@bps($product->interest_rate_bps) p.a.</td>
                                <td class="px-4 py-2.5 text-right text-slate-600">@money($product->min_balance)</td>
                                <td class="px-4 py-2.5 text-right text-slate-600">{{ $product->accounts_count }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex flex-wrap gap-1">
                                        @if ($product->allows_withdrawal)<x-badge status="info" label="Withdrawals" />@endif
                                        @if ($product->qualifies_for_loan)<x-badge status="success" label="Loan security" />@endif
                                        @if ($product->is_mandatory)<x-badge status="warning" label="Mandatory" />@endif
                                        <x-badge :status="$product->is_active ? 'active' : 'inactive'" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endsection

@extends('layouts.app')

@section('title', 'Investments')

@section('content')
    @php
        $productOptions = $products->mapWithKeys(fn ($product) => [$product->id => $product->name.' ('.$product->code.')'])->all();
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
        $typeOptions = \App\Models\InvestmentProduct::TYPES;
        $periodOptions = \App\Models\InvestmentProduct::PERIODS;
        $methodOptions = \App\Models\InvestmentProduct::METHODS;
    @endphp

    <x-page-header title="Investments" :subtitle="$investments->total().' investment(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($canDeposit)
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="trending-up" class="h-4 w-4" /> Record investment
                </summary>
                <form method="POST" action="{{ route('investments.place') }}" class="absolute right-0 z-20 mt-2 w-96 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Record a member investment</p>
                    <x-select name="member_id" label="Member" :options="$memberOptions" placeholder="Select a member" required />
                    <x-select name="investment_product_id" label="Product" :options="$productOptions" placeholder="Select a product" required />
                    <div class="grid grid-cols-2 gap-3">
                        <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                        <x-input name="start_date" type="date" label="Start date" :value="now()->toDateString()" />
                    </div>
                    <x-select name="funding_source" label="Funding source" :options="['wallet' => 'Member wallet', 'cash' => 'Cash', 'bank' => 'Bank']" required />
                    <x-input name="reference" label="Reference" placeholder="Receipt or bank reference" />
                    <x-textarea name="notes" label="Notes" rows="2" />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Record investment</button>
                </form>
            </details>
        @endif
        @if ($canManage)
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="cog" class="h-4 w-4" /> New product
                </summary>
                <form method="POST" action="{{ route('investments.products.store') }}" class="absolute right-0 z-20 mt-2 w-[26rem] space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Create an investment product</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-input name="name" label="Name" required />
                        <x-input name="code" label="Code" required />
                        <x-select name="type" label="Type" :options="$typeOptions" required />
                        <x-input name="interest_rate" type="number" step="0.01" min="0" label="Annual rate (%)" required />
                        <x-select name="interest_period" label="Interest period" :options="$periodOptions" required />
                        <x-select name="interest_method" label="Method" :options="$methodOptions" required />
                        <x-input name="duration_months" type="number" min="1" max="120" label="Term (months)" required />
                        <x-input name="min_amount" type="number" step="0.01" label="Minimum amount" />
                        <x-input name="max_amount" type="number" step="0.01" label="Maximum amount" />
                        <x-input name="early_withdrawal_penalty" type="number" step="0.01" min="0" max="100" label="Early exit penalty (%)" />
                    </div>
                    <x-textarea name="description" label="Description" rows="2" />
                    <x-textarea name="terms" label="Terms" rows="2" />
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="early_withdrawal_allowed" value="1" class="rounded border-slate-300 text-brand-600"> Allow early withdrawal
                    </label>
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create product</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Principal held" :value="\App\Support\Money::format($principalTotal)" hint="Across open investments" />
        <x-stat label="Accrued interest" :value="\App\Support\Money::format($interestTotal)" hint="Interest owed to members" tone="warning" />
        <x-stat label="Matured" :value="number_format($maturedCount)" hint="Awaiting payout" tone="info" />
        <x-stat label="Products" :value="number_format($products->count())" hint="Configured investment products" tone="neutral" />
    </div>

    <div class="mt-6">
        <x-card title="Investments" :subtitle="$investments->total().' record(s)'" :padding="false">
            @if ($investments->isEmpty())
                <x-empty title="No investments" message="Record an investment or adjust the filters." class="m-4" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Reference</th>
                                <th class="px-4 py-3">Member</th>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3 text-right">Principal</th>
                                <th class="px-4 py-3 text-right">Interest</th>
                                <th class="px-4 py-3 text-right">Value</th>
                                <th class="px-4 py-3">Maturity</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($investments as $investment)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3 font-mono text-xs font-medium text-slate-900">
                                        <a href="{{ route('investments.show', $investment) }}" class="hover:text-brand-700">{{ $investment->reference_no }}</a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('members.show', $investment->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $investment->member?->full_name ?? '—' }}</a>
                                        <p class="text-xs text-slate-400">{{ $investment->member?->member_no }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ $investment->product?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-slate-900">@money($investment->principal)</td>
                                    <td class="px-4 py-3 text-right text-emerald-700">@money($investment->accrued_interest)</td>
                                    <td class="px-4 py-3 text-right text-slate-700">@money($investment->totalValue())</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $investment->maturity_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <x-badge :status="$investment->status === 'matured' ? 'info' : ($investment->status === 'active' ? 'active' : 'inactive')" :label="$investment->statusLabel()" />
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('investments.show', $investment) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-200 px-4 py-3">{{ $investments->links() }}</div>
            @endif
        </x-card>
    </div>

    <div class="mt-6">
        <x-card title="Investment products" :padding="false">
            @if ($products->isEmpty())
                <x-empty title="No products configured" message="Create a product before members can invest." class="m-4" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5">Product</th>
                                <th class="px-4 py-2.5">Type</th>
                                <th class="px-4 py-2.5 text-right">Rate</th>
                                <th class="px-4 py-2.5">Period / method</th>
                                <th class="px-4 py-2.5 text-right">Term</th>
                                <th class="px-4 py-2.5 text-right">Minimum</th>
                                <th class="px-4 py-2.5 text-right">Investments</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($products as $product)
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <p class="font-medium text-slate-900">{{ $product->name }}</p>
                                        <p class="font-mono text-xs text-slate-500">{{ $product->code }}</p>
                                    </td>
                                    <td class="px-4 py-2.5"><x-badge :status="$product->type" :label="$product->typeLabel()" /></td>
                                    <td class="px-4 py-2.5 text-right text-slate-600">@bps($product->interest_rate_bps) p.a.</td>
                                    <td class="px-4 py-2.5 text-slate-600">
                                        <p>{{ $product->periodLabel() }}</p>
                                        <p class="text-xs text-slate-400">{{ $product->methodLabel() }}</p>
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-slate-600">{{ $product->duration_months }} mo</td>
                                    <td class="px-4 py-2.5 text-right text-slate-600">@money($product->min_amount)</td>
                                    <td class="px-4 py-2.5 text-right text-slate-600">{{ $product->investments_count }}</td>
                                    <td class="px-4 py-2.5">
                                        <div class="flex flex-wrap gap-1">
                                            <x-badge :status="$product->is_active ? 'active' : 'inactive'" />
                                            @if ($product->early_withdrawal_allowed)
                                                <x-badge status="info" label="Early exit" />
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        @if ($canManage)
                                            <div class="flex items-center justify-end gap-2">
                                                <details class="relative">
                                                    <summary class="cursor-pointer text-sm font-medium text-brand-700 hover:text-brand-800">Edit</summary>
                                                    <form method="POST" action="{{ route('investments.products.update', $product) }}" class="absolute right-0 z-20 mt-2 w-[26rem] space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                                                        @csrf
                                                        @method('PUT')
                                                        <p class="text-sm font-semibold text-slate-900">Edit {{ $product->name }}</p>
                                                        <div class="grid gap-3 sm:grid-cols-2">
                                                            <x-input name="name" label="Name" :value="$product->name" required />
                                                            <x-input name="code" label="Code" :value="$product->code" required />
                                                            <x-select name="type" label="Type" :options="$typeOptions" :value="$product->type" required />
                                                            <x-input name="interest_rate" type="number" step="0.01" min="0" label="Annual rate (%)" :value="$product->interest_rate_bps / 100" required />
                                                            <x-select name="interest_period" label="Interest period" :options="$periodOptions" :value="$product->interest_period" required />
                                                            <x-select name="interest_method" label="Method" :options="$methodOptions" :value="$product->interest_method" required />
                                                            <x-input name="duration_months" type="number" min="1" max="120" label="Term (months)" :value="$product->duration_months" required />
                                                            <x-input name="min_amount" type="number" step="0.01" label="Minimum amount" :value="$product->min_amount / 100" />
                                                            <x-input name="max_amount" type="number" step="0.01" label="Maximum amount" :value="$product->max_amount !== null ? $product->max_amount / 100 : null" />
                                                            <x-input name="early_withdrawal_penalty" type="number" step="0.01" min="0" max="100" label="Early exit penalty (%)" :value="$product->early_withdrawal_penalty_bps / 100" />
                                                        </div>
                                                        <x-textarea name="description" label="Description" rows="2" :value="$product->description" />
                                                        <x-textarea name="terms" label="Terms" rows="2" :value="$product->terms" />
                                                        <label class="flex items-center gap-2 text-sm text-slate-600">
                                                            <input type="checkbox" name="early_withdrawal_allowed" value="1" @checked($product->early_withdrawal_allowed) class="rounded border-slate-300 text-brand-600"> Allow early withdrawal
                                                        </label>
                                                        <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save product</button>
                                                    </form>
                                                </details>
                                                <form method="POST" action="{{ route('investments.products.toggle', $product) }}">
                                                    @csrf
                                                    <button type="submit" class="text-sm font-medium {{ $product->is_active ? 'text-rose-600 hover:text-rose-700' : 'text-emerald-600 hover:text-emerald-700' }}">
                                                        {{ $product->is_active ? 'Close' : 'Open' }}
                                                    </button>
                                                </form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
@endsection

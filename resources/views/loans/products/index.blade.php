@extends('layouts.app')

@section('title', 'Loan products')

@section('content')
    <x-page-header title="Loan products" subtitle="Define the loan types members can apply for, with the requirements that apply to each one.">
        @if ($canManage)
            <a href="{{ route('loan-products.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> New loan product
            </a>
        @endif
    </x-page-header>

    @unless ($canManage)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            You can view the configured loan products. Ask a group administrator to create or edit them.
        </div>
    @endunless

    @if ($products->isEmpty())
        <x-empty title="No loan products configured" message="Create a product type to start accepting loan applications." />
    @else
        <x-card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Interest</th>
                            <th class="px-4 py-3">Amount range</th>
                            <th class="px-4 py-3">Term</th>
                            <th class="px-4 py-3">Guarantors</th>
                            <th class="px-4 py-3">Loans</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($products as $product)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-900">{{ $product->name }}</p>
                                    <p class="text-xs text-slate-400">{{ $product->code }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $product->categoryLabel() }}</td>
                                <td class="px-4 py-3 text-slate-600">
                                    {{ $product->methodLabel() }}
                                    <p class="text-xs text-slate-400">
                                        {{ number_format($product->interest_rate_bps / 100, 2) }}% per {{ $product->interest_period }}
                                        @if ($product->accrual_mode === 'effective')
                                            · effective
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">
                                    {{ \App\Support\Money::format($product->min_amount) }}
                                    <span class="text-slate-400">–</span>
                                    {{ \App\Support\Money::format($product->max_amount) }}
                                    <p class="text-xs text-slate-400">Up to {{ \App\Support\Money::multiplier($product->multiplier_bps) }} savings</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">
                                    {{ $product->duration_min }}–{{ $product->duration_max }} {{ $product->duration_unit }}
                                    <p class="text-xs text-slate-400">{{ ucfirst($product->repayment_frequency) }} repayments</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">
                                    @php $mode = $product->guarantorMode(); @endphp
                                    @if ($mode === 'required')
                                        Required · {{ $product->min_guarantors }} min
                                        <p class="text-xs text-slate-400">{{ number_format($product->guarantor_coverage_bps / 100, 0) }}% coverage</p>
                                    @elseif ($mode === 'optional')
                                        Optional
                                        <p class="text-xs text-slate-400">With or without guarantors</p>
                                    @else
                                        Not used
                                        <p class="text-xs text-slate-400">Savings multiplier only</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ number_format($product->loans_count) }}</td>
                                <td class="px-4 py-3">
                                    @if ($product->is_active)
                                        <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">Active</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">Inactive</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($canManage)
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ route('loan-products.edit', $product) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Edit</a>
                                            <form method="POST" action="{{ route('loan-products.toggle', $product) }}">
                                                @csrf
                                                <button type="submit" class="text-sm font-medium text-slate-500 hover:text-slate-700">
                                                    {{ $product->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400">View only</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
@endsection

@extends('layouts.app')

@section('title', 'Bills')

@section('content')
    @php
        $money = fn (int $minor) => \App\Support\Money::format($minor);
    @endphp

    <x-page-header title="Bills" subtitle="Issue bills to members or customers and record payments.">
        @if ($canManage)
            <a href="{{ route('bills.create') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                New bill
            </a>
        @endif
    </x-page-header>

    <div class="mt-6 space-y-5">
        <x-card title="All bills" :padding="false">
            <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                <x-select name="status" label="Status" :options="['' => 'All statuses'] + array_combine($statuses, $statuses)" :value="request('status')" />
                <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Filter</button>
            </form>

            @if ($bills->isEmpty())
                <div class="p-6"><x-empty title="No bills yet" message="Create a bill to start collecting payments." /></div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Bill</th>
                                <th class="px-4 py-3">Payee</th>
                                <th class="px-4 py-3">Amount</th>
                                <th class="px-4 py-3">Paid</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($bills as $bill)
                                <tr>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('bills.show', $bill) }}" class="font-semibold text-brand-700 hover:underline">{{ $bill->bill_no }}</a>
                                        <p class="text-xs text-slate-500">{{ $bill->created_at?->format('d M Y') }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-slate-800">{{ $bill->payee_name }}</p>
                                        <p class="text-xs text-slate-500">{{ $bill->member?->full_name ?? $bill->payee_account ?? 'Customer account' }}</p>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800">{{ $money($bill->amount) }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $money($bill->amount_paid) }}</td>
                                    <td class="px-4 py-3"><x-badge :status="$bill->status" /></td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('bills.show', $bill) }}" class="text-sm font-semibold text-brand-700 hover:underline">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $bills->links() }}</div>
            @endif
        </x-card>
    </div>
@endsection

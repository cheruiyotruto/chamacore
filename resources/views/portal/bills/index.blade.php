@extends('layouts.portal')

@section('title', 'My bills')

@section('content')
    @php
        $money = fn (int $minor) => \App\Support\Money::format($minor);
    @endphp

    <x-page-header title="My bills" subtitle="View and pay bills issued to your member account." />

    <div class="mt-6">
        <x-card title="Bills" :padding="false">
            @if ($bills->isEmpty())
                <div class="p-6">
                    <x-empty title="No bills" message="Bills issued to you will appear here." />
                </div>
            @else
                <div class="space-y-3 p-4">
                    @foreach ($bills as $bill)
                        <a href="{{ route('portal.bills.show', $bill) }}"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-slate-50">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $bill->bill_no }}</p>
                                <p class="text-xs text-slate-500">{{ $bill->payeeLabel() }} · due {{ $bill->due_date?->format('d M Y') ?? '—' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-brand-700">{{ $money($bill->outstanding()) }}</p>
                                <p class="text-xs text-slate-500">Outstanding</p>
                            </div>
                            <x-badge :status="$bill->status" />
                        </a>
                    @endforeach
                </div>
                <div class="border-t border-slate-200 p-4">{{ $bills->links() }}</div>
            @endif
        </x-card>
    </div>
@endsection

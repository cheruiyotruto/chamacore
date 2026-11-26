@extends('layouts.app')

@section('title', 'Float purchases')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">M-Pesa float purchases</h1>
            <p class="mt-1 text-sm text-slate-500">Fulfil member float requests or fail them to refund the wallet automatically.</p>
            <a href="{{ route('mpesa-agents.index') }}" class="mt-1 inline-block text-sm font-medium text-brand-700 hover:text-brand-800">Manage M-Pesa agents →</a>
        </div>
        <form method="GET" action="{{ route('float-purchases.index') }}" class="flex items-center gap-2">
            <select name="status" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                <option value="">All statuses</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Filter</button>
        </form>
    </div>

    <x-card :subtitle="$purchases->total().' purchase(s)'" :padding="false">
        @if ($purchases->isEmpty())
            <x-empty title="No float purchases" message="Member purchases will appear here." class="m-4" />
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($purchases as $purchase)
                    <div class="px-4 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900">
                                    {{ $purchase->member?->full_name ?? 'Former member' }} · @money($purchase->amount)
                                </p>
                                <p class="mt-0.5 font-mono text-xs text-slate-400">{{ $purchase->reference }}</p>
                                <p class="mt-0.5 text-xs font-medium text-slate-700">
                                    Agent {{ $purchase->agent_number ?: '—' }} · Store {{ $purchase->store_number ?: '—' }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $purchase->phone }} · {{ $purchase->created_at?->format('d M Y H:i') ?? '—' }}
                                    @if ($purchase->provider_reference)
                                        · Provider {{ $purchase->provider_reference }}
                                    @endif
                                </p>
                                @if ($purchase->failure_reason)
                                    <p class="mt-1 text-xs text-rose-600">{{ $purchase->failure_reason }}</p>
                                @endif
                            </div>
                            <div class="text-right">
                                <x-badge :status="$purchase->status" :label="$statuses[$purchase->status] ?? ucfirst($purchase->status)" />
                                <p class="mt-1 text-xs text-slate-500">Charged @money($purchase->total_amount)</p>
                            </div>
                        </div>

                        @if (! $purchase->isFinal())
                            <div class="mt-3 flex flex-wrap items-end gap-3 rounded-lg bg-slate-50 p-3">
                                <form method="POST" action="{{ route('float-purchases.fulfil', $purchase) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <x-input name="provider_reference" type="text" label="Provider reference (optional)" :value="$purchase->provider_reference" class="w-48" />
                                    <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Mark fulfilled</button>
                                </form>
                                <form method="POST" action="{{ route('float-purchases.fail', $purchase) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <x-input name="reason" type="text" label="Failure reason" placeholder="Store not recharged" required class="w-56" />
                                    <button type="submit" class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700">Fail &amp; refund</button>
                                </form>
                                @if ($purchase->provider_reference)
                                    <form method="POST" action="{{ route('float-purchases.refresh', $purchase) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-white">
                                            <x-icon name="refresh" class="mr-1 inline h-4 w-4" /> Refresh status
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $purchases->links() }}</div>
        @endif
    </x-card>
@endsection

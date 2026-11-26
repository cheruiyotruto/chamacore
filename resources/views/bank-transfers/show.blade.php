@extends('layouts.app')

@section('title', 'Transfer '.$payout->uuid)

@section('content')
    @php
        $money = fn (?int $minor) => \App\Support\Money::format($minor ?? 0);
    @endphp

    <x-page-header :title="'Transfer '.$payout->uuid" :subtitle="$payout->destination?->name ?? 'Payment destination'">
        @if ($canManage && ! $payout->isFinal())
            <form method="POST" action="{{ route('bank-transfers.cancel', $payout) }}">
                @csrf
                <button class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Cancel and release funds
                </button>
            </form>
        @endif
    </x-page-header>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="Transfer summary">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Status</dt><dd class="mt-0.5"><x-badge :status="$payout->status" /></dd></div>
                    <div><dt class="text-slate-500">Provider</dt><dd class="mt-0.5 capitalize text-slate-800">{{ $payout->provider }}</dd></div>
                    <div><dt class="text-slate-500">Destination</dt><dd class="mt-0.5 text-slate-800">{{ $payout->destination?->label() }}</dd></div>
                    <div><dt class="text-slate-500">Funded from</dt><dd class="mt-0.5 text-slate-800">{{ $payout->source === 'wallet' ? 'Member wallet' : 'Collected bill funds' }}</dd></div>
                    <div><dt class="text-slate-500">Amount</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $money($payout->amount) }}</dd></div>
                    <div><dt class="text-slate-500">Recipient receives</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $money($payout->net_amount) }}</dd></div>
                    <div><dt class="text-slate-500">Charge</dt><dd class="mt-0.5 text-slate-800">{{ $money($payout->charge_amount) }}</dd></div>
                    <div><dt class="text-slate-500">Source debit</dt><dd class="mt-0.5 text-slate-800">{{ $money($payout->total_amount) }}</dd></div>
                    <div><dt class="text-slate-500">Provider reference</dt><dd class="mt-0.5 font-mono text-xs text-slate-800">{{ $payout->provider_reference ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Completed</dt><dd class="mt-0.5 text-slate-800">{{ $payout->paid_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                </dl>
                @if ($payout->failure_reason)
                    <p class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ $payout->failure_reason }}</p>
                @endif
            </x-card>

            <x-card title="Charge breakdown" :padding="false">
                @if (empty($payout->charge_breakdown))
                    <div class="p-6"><x-empty title="No charge applied" /></div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr><th class="px-4 py-3">Rule</th><th class="px-4 py-3">Formula</th><th class="px-4 py-3">Borne by</th><th class="px-4 py-3">Amount</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($payout->charge_breakdown as $line)
                                    <tr>
                                        <td class="px-4 py-3 text-slate-800">{{ $line['name'] }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ $line['mode'] }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ $line['borne_by'] }}</td>
                                        <td class="px-4 py-3 font-medium text-slate-800">{{ $money($line['amount'] ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <x-card title="Timeline">
            <ol class="space-y-4 text-sm">
                <li>
                    <p class="font-semibold text-slate-900">Requested</p>
                    <p class="text-xs text-slate-500">{{ $payout->created_at?->format('d M Y H:i') }} · {{ $payout->requester?->name }}</p>
                </li>
                <li>
                    <p class="font-semibold text-slate-900">Reserved</p>
                    <p class="text-xs text-slate-500">Journal #{{ data_get($payout->payload, 'reservation_journal_id', '—') }}</p>
                </li>
                @if ($payout->approved_by)
                    <li>
                        <p class="font-semibold text-slate-900">Sent</p>
                        <p class="text-xs text-slate-500">{{ $payout->approver?->name }}</p>
                    </li>
                @endif
                @if ($payout->paid_at)
                    <li>
                        <p class="font-semibold text-slate-900">Confirmed</p>
                        <p class="text-xs text-slate-500">{{ $payout->paid_at->format('d M Y H:i') }}</p>
                    </li>
                @endif
            </ol>
        </x-card>
    </div>
@endsection

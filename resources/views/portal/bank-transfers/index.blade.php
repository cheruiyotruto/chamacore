@extends('layouts.portal')

@section('title', 'Bank transfers')

@section('content')
    @php
        $money = fn (?int $minor) => \App\Support\Money::format($minor ?? 0);
    @endphp

    <x-page-header title="Bank transfers"
        subtitle="Send money from your wallet to a bank account or M-Pesa organisation." />

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="Transfer history" :padding="false">
                @if ($payouts->isEmpty())
                    <div class="p-6"><x-empty title="No transfers yet" message="Use the form to send your first transfer." /></div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Reference</th>
                                    <th class="px-4 py-3">Destination</th>
                                    <th class="px-4 py-3">Amount</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($payouts as $payout)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-800">{{ $payout->uuid }}</td>
                                        <td class="px-4 py-3 text-slate-700">{{ $payout->destination?->name }}</td>
                                        <td class="px-4 py-3 font-medium text-slate-800">{{ $money($payout->amount) }}</td>
                                        <td class="px-4 py-3"><x-badge :status="$payout->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-4">{{ $payouts->links() }}</div>
                @endif
            </x-card>
        </div>

        <x-card title="Send money" subtitle="Charges are calculated from the group's payment charge settings.">
            @if ($destinations->isEmpty())
                <x-empty title="No destinations" message="Ask an administrator to add a destination." />
            @else
                <form method="POST" action="{{ route('portal.bank-transfers.store') }}" class="space-y-4">
                    @csrf
                    <x-select name="destination_id" label="Destination"
                        :options="$destinations->mapWithKeys(fn ($d) => [$d->id => $d->name.' · '.$d->label()])->all()"
                        required />
                    <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                    <x-input name="manual_charge" type="number" step="0.01" min="0" label="Manual charge"
                        help="Only used when a charge rule is set to 'Entered manually per payment'." />
                    <button class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        Send transfer
                    </button>
                </form>
            @endif
        </x-card>
    </div>
@endsection

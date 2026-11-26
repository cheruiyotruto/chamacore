@extends('layouts.portal')

@section('title', 'Bill '.$bill->bill_no)

@section('content')
    @php
        $money = fn (int $minor) => \App\Support\Money::format($minor);
        $phone = old('phone', auth()->user()?->phone ?? '');
    @endphp

    <x-page-header :title="$bill->bill_no" :subtitle="$bill->payeeLabel()" />

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="Bill summary">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Amount</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">{{ $money($bill->amount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Paid</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">{{ $money($bill->amount_paid) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Outstanding</dt>
                        <dd class="mt-0.5 font-semibold text-brand-700">{{ $money($bill->outstanding()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Due date</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $bill->due_date?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Status</dt>
                        <dd class="mt-0.5"><x-badge :status="$bill->status" /></dd>
                    </div>
                </dl>
                @if ($bill->description)
                    <p class="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-600">{{ $bill->description }}</p>
                @endif
            </x-card>

            <x-card title="Payment history" :padding="false">
                @if ($bill->payments->isEmpty())
                    <div class="p-6"><x-empty title="No payments recorded" /></div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Reference</th>
                                    <th class="px-4 py-3">Date</th>
                                    <th class="px-4 py-3">Method</th>
                                    <th class="px-4 py-3">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($bill->payments as $payment)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-800">{{ $payment->reference }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ $payment->paid_at?->format('d M Y H:i') }}</td>
                                        <td class="px-4 py-3 capitalize text-slate-600">{{ $payment->method }}</td>
                                        <td class="px-4 py-3 font-medium text-slate-800">{{ $money($payment->amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        @if (! $bill->isFullyPaid() && $bill->status !== \App\Models\Bill::STATUS_CANCELLED)
            <div class="space-y-5">
                <x-card title="Pay from wallet" subtitle="Any applicable charge is debited from your wallet as well.">
                    <form method="POST" action="{{ route('portal.bills.pay-wallet', $bill) }}" class="space-y-4">
                        @csrf
                        <x-input name="amount" type="number" step="0.01" min="0.01"
                            :value="old('amount', $bill->outstanding() / 100)" label="Amount" required />
                        <x-input name="manual_charge" type="number" step="0.01" min="0"
                            :value="old('manual_charge')" label="Manual charge"
                            help="Only used when the group uses a manual payment charge." />
                        <button class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Pay bill</button>
                    </form>
                </x-card>

                <x-card title="Pay with M-Pesa" subtitle="You will receive an STK push on your phone.">
                    <form method="POST" action="{{ route('portal.bills.pay-mpesa', $bill) }}" class="space-y-4">
                        @csrf
                        <x-input name="amount" type="number" step="0.01" min="0.01"
                            :value="old('amount', $bill->outstanding() / 100)" label="Amount" required />
                        <x-input name="phone" :value="$phone" label="M-Pesa phone number" required />
                        <x-input name="manual_charge" type="number" step="0.01" min="0"
                            :value="old('manual_charge')" label="Manual charge" />
                        <button class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Request payment</button>
                    </form>
                </x-card>
            </div>
        @endif
    </div>
@endsection

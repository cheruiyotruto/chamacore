@extends('layouts.portal')

@section('title', 'Pay for a member')

@section('content')
    @php
        $forms = [
            'savings' => [
                'title' => 'Savings deposit',
                'subtitle' => 'Credit another member\'s savings account.',
                'action' => route('portal.on-behalf.savings'),
                'itemName' => 'savings_account_id',
                'itemLabel' => 'Savings account',
                'itemOptions' => $savingsAccounts,
                'itemPlaceholder' => 'Select a savings account',
                'amount' => true,
                'charge' => true,
                'icon' => 'banknotes',
            ],
            'loan' => [
                'title' => 'Loan repayment',
                'subtitle' => 'Reduce another member\'s outstanding loan.',
                'action' => route('portal.on-behalf.loan'),
                'itemName' => 'loan_id',
                'itemLabel' => 'Loan',
                'itemOptions' => $loans,
                'itemPlaceholder' => 'Select a loan',
                'amount' => true,
                'charge' => true,
                'icon' => 'credit-card',
            ],
            'bill' => [
                'title' => 'Bill payment',
                'subtitle' => 'Settle a bill raised against another member.',
                'action' => route('portal.on-behalf.bill'),
                'itemName' => 'bill_id',
                'itemLabel' => 'Bill',
                'itemOptions' => $bills,
                'itemPlaceholder' => 'Select a bill',
                'amount' => true,
                'charge' => true,
                'icon' => 'receipt',
            ],
            'mgr' => [
                'title' => 'Merry-go-round contribution',
                'subtitle' => 'Pay a contribution and any late penalty for another member.',
                'action' => route('portal.on-behalf.mgr'),
                'itemName' => 'contribution_id',
                'itemLabel' => 'Contribution',
                'itemOptions' => $contributions,
                'itemPlaceholder' => 'Select a contribution',
                'amount' => false,
                'charge' => true,
                'icon' => 'calendar',
            ],
            'wallet' => [
                'title' => 'Wallet credit',
                'subtitle' => 'Add money to another member\'s wallet.',
                'action' => route('portal.on-behalf.wallet'),
                'itemName' => null,
                'itemLabel' => null,
                'itemOptions' => null,
                'itemPlaceholder' => null,
                'amount' => true,
                'charge' => false,
                'icon' => 'wallet',
            ],
        ];
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">Pay for a member</h1>
        <p class="mt-1 text-sm text-slate-500">
            Paying as <span class="font-medium text-slate-700">{{ $payer->full_name }}</span> ·
            Available wallet balance <span class="font-medium text-slate-700">@money($walletBalance)</span>
        </p>
    </div>

    <x-card title="How on-behalf payments work" subtitle="Both you and the beneficiary are recorded.">
        <ul class="grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Your wallet is debited, or M-Pesa sends a prompt to the phone you enter.</li>
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> The savings, loan, bill, contribution or wallet belongs to the member you select.</li>
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Every payment records the payer and beneficiary for audit.</li>
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> M-Pesa payments are credited only after the provider callback succeeds.</li>
        </ul>
    </x-card>

    <div class="mt-6 grid gap-5 lg:grid-cols-2">
        @foreach ($forms as $key => $form)
            <x-card :title="$form['title']" :subtitle="$form['subtitle']">
                @if (empty($memberOptions) || ($form['itemOptions'] !== null && $form['itemOptions'] === []))
                    <x-empty
                        title="Nothing to pay yet"
                        message="There are no other active members or payable items available for this payment type."
                    />
                @else
                    <form method="POST" action="{{ $form['action'] }}" class="space-y-3" x-data="{ method: @js(old('method', 'wallet')) }">
                        @csrf

                        <x-select
                            id="{{ $key }}-beneficiary"
                            name="beneficiary_id"
                            label="Member to pay for"
                            :options="$memberOptions"
                            placeholder="Select a member"
                            required
                        />

                        @if ($form['itemName'])
                            <x-select
                                id="{{ $key }}-item"
                                name="{{ $form['itemName'] }}"
                                :label="$form['itemLabel']"
                                :options="$form['itemOptions']"
                                :placeholder="$form['itemPlaceholder']"
                                required
                            />
                        @endif

                        @if ($form['amount'])
                            <x-input
                                id="{{ $key }}-amount"
                                name="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                label="Amount (KES)"
                                required
                            />
                        @endif

                        <x-select
                            id="{{ $key }}-method"
                            name="method"
                            label="Pay using"
                            :options="$methodOptions"
                            x-model="method"
                            required
                        />

                        <x-input
                            id="{{ $key }}-phone"
                            name="phone"
                            type="tel"
                            :label="'M-Pesa phone'"
                            :value="$payer->phone"
                            placeholder="07xx xxx xxx"
                            help="Required when paying with M-Pesa."
                            x-bind:required="method === 'mpesa'"
                        />

                        @if ($form['charge'])
                            <x-input
                                id="{{ $key }}-charge"
                                name="manual_charge"
                                type="number"
                                step="0.01"
                                min="0"
                                label="Override charge (KES)"
                                help="Leave blank to use the configured payment charges."
                            />
                        @endif

                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                            <x-icon :name="$form['icon']" class="h-4 w-4" /> Pay for member
                        </button>
                    </form>
                @endif
            </x-card>
        @endforeach
    </div>

    <div class="mt-6">
        <x-card title="Payments you made" subtitle="Your most recent on-behalf payments." :padding="false">
            @if ($recent->isEmpty())
                <x-empty title="No on-behalf payments" message="Payments you make for other members will appear here." class="m-4" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2">Date</th>
                                <th class="px-4 py-2">Type</th>
                                <th class="px-4 py-2">Beneficiary</th>
                                <th class="px-4 py-2">Amount</th>
                                <th class="px-4 py-2">Method</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($recent as $payment)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-2 text-slate-500">{{ $payment->created_at?->format('d M Y H:i') ?? '—' }}</td>
                                    <td class="px-4 py-2 font-medium text-slate-800">{{ $types[$payment->type] ?? ucfirst(str_replace('_', ' ', $payment->type)) }}</td>
                                    <td class="px-4 py-2 text-slate-700">{{ $payment->member?->full_name ?? 'Member #'.$payment->member_id }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 font-semibold text-slate-800">@money($payment->amount)</td>
                                    <td class="px-4 py-2 uppercase text-slate-500">{{ $payment->method }}</td>
                                    <td class="px-4 py-2"><x-badge :status="$payment->status" /></td>
                                    <td class="max-w-[12rem] truncate px-4 py-2 font-mono text-xs text-slate-500" title="{{ $payment->reference }}">{{ $payment->reference }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
@endsection

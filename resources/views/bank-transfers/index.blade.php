@extends('layouts.app')

@section('title', 'Bank transfers')

@section('content')
    @php
        $money = fn (?int $minor) => \App\Support\Money::format($minor ?? 0);
    @endphp

    <x-page-header title="Bank transfers"
        subtitle="Send collected funds or a member's wallet balance to bank accounts and M-Pesa organisations." />

    <div class="mt-6 space-y-5">
        @if ($canManage)
            <x-card title="Member access" subtitle="Closed by default. Enable the feature, then opt in the members who may send money.">
                <form method="POST" action="{{ route('bank-transfers.access.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                        <input type="hidden" name="members_enabled" value="0">
                        <input type="checkbox" name="members_enabled" value="1" @checked($groupAccessEnabled)
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm font-semibold text-slate-900">Open bank transfers for members</span>
                            <span class="block text-xs text-slate-500">When off, no member can initiate a transfer, even if individually enabled.</span>
                        </span>
                    </label>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-slate-900">Members allowed to transfer</p>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @forelse ($members as $memberOption)
                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
                                    <input type="hidden" name="members[{{ $memberOption->id }}]" value="0">
                                    <input type="checkbox" name="members[{{ $memberOption->id }}]" value="1"
                                        @checked($memberOption->can_bank_transfer)
                                        class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <span class="truncate">{{ $memberOption->full_name }}</span>
                                </label>
                            @empty
                                <p class="text-sm text-slate-500">No active members yet.</p>
                            @endforelse
                        </div>
                    </div>

                    <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        Save access
                    </button>
                </form>
            </x-card>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">
            <x-card title="Payment destinations" subtitle="Bank accounts, PayBills, tills and M-Pesa phone numbers.">
                <div class="space-y-3">
                    @forelse ($destinations as $destination)
                        <div class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ $destination->name }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ $destination->label() }}</p>
                                <p class="mt-1 text-[11px] uppercase tracking-wide text-slate-400">{{ $types[$destination->type] ?? $destination->type }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-badge :status="$destination->is_active ? 'active' : 'inactive'" />
                                @if ($canManage)
                                    <form method="POST" action="{{ route('bank-transfers.destinations.toggle', $destination) }}">
                                        @csrf
                                        <button class="text-xs font-semibold text-brand-700 hover:underline">
                                            {{ $destination->is_active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <x-empty title="No destinations yet" message="Add a bank account or PayBill below." />
                    @endforelse
                </div>

                @if ($canManage)
                    <form method="POST" action="{{ route('bank-transfers.destinations.store') }}" class="mt-5 space-y-4 border-t border-slate-100 pt-5">
                        @csrf
                        <x-input name="name" label="Destination name" placeholder="County Water PayBill" required />
                        <x-select name="type" label="Destination type" :options="$types" value="bank" required />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-input name="bank_name" label="Bank name" :value="old('bank_name')" />
                            <x-input name="account_name" label="Account name" :value="old('account_name')" />
                            <x-input name="account_number" label="Account number" :value="old('account_number')" />
                            <x-input name="paybill_number" label="PayBill number" :value="old('paybill_number')" />
                            <x-input name="till_number" label="Till number" :value="old('till_number')" />
                            <x-input name="phone" label="Phone number (B2C)" :value="old('phone')" />
                        </div>
                        <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                            Add destination
                        </button>
                    </form>
                @endif
            </x-card>

            @if ($canManage)
                <x-card title="Send money" subtitle="Funds are reserved immediately and released if the provider reports failure.">
                    <form method="POST" action="{{ route('bank-transfers.payouts.store') }}" class="space-y-4">
                        @csrf
                        <x-select name="destination_id" label="Destination"
                            :options="$destinations->mapWithKeys(fn ($d) => [$d->id => $d->name.' · '.$d->label()])->all()"
                            required />
                        <x-select name="source" label="Funded from" :options="$sources" value="bill_collections" required />
                        <x-select name="bill_id" label="Bill (optional)" :options="['' => 'Not linked to a bill'] + $bills->mapWithKeys(fn ($b) => [$b->id => $b->bill_no.' · '.$b->payee_name])->all()" />
                        <x-select name="member_id" label="Wallet owner (when funded from a wallet)"
                            :options="['' => 'Not applicable'] + $members->mapWithKeys(fn ($m) => [$m->id => $m->full_name])->all()" />
                        <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                        <x-input name="manual_charge" type="number" step="0.01" min="0" label="Manual charge"
                            help="Only used when a charge rule is set to 'Entered manually per payment'." />
                        <button class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                            Send transfer
                        </button>
                    </form>
                </x-card>
            @endif
        </div>

        <x-card title="Transfers" :padding="false">
            <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                <x-select name="status" label="Status" :options="['' => 'All statuses'] + array_combine($statuses, $statuses)" :value="request('status')" />
                <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Filter</button>
            </form>

            @if ($payouts->isEmpty())
                <div class="p-6"><x-empty title="No transfers yet" message="Send money using the form above." /></div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Reference</th>
                                <th class="px-4 py-3">Destination</th>
                                <th class="px-4 py-3">Amount</th>
                                <th class="px-4 py-3">Charge</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($payouts as $payout)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-800">{{ $payout->uuid }}</td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-slate-800">{{ $payout->destination?->name }}</p>
                                        <p class="text-xs text-slate-500">{{ $payout->destination?->label() }}</p>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800">{{ $money($payout->amount) }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $money($payout->charge_amount) }}</td>
                                    <td class="px-4 py-3"><x-badge :status="$payout->status" /></td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('bank-transfers.show', $payout) }}" class="text-sm font-semibold text-brand-700 hover:underline">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $payouts->links() }}</div>
            @endif
        </x-card>
    </div>
@endsection

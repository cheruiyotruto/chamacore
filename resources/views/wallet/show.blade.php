@extends('layouts.app')

@section('title', 'Wallet · '.($wallet->member?->full_name ?? 'Member'))

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $held = max(0, $wallet->balance - $wallet->available_balance);
    @endphp

    <x-page-header :title="'Wallet · '.($wallet->member?->full_name ?? 'Member')" :subtitle="($wallet->member?->member_no ?? '—').' · opened '.($wallet->created_at?->format('d M Y') ?? '—')">
        <a href="{{ route('wallet.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All wallets
        </a>
        @if ($wallet->member)
            <a href="{{ route('members.show', $wallet->member) }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
                <x-icon name="users" class="h-4 w-4" /> Member profile
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Balance" :value="\App\Support\Money::format($wallet->balance)" hint="Total wallet float" />
        <x-stat label="Available" :value="\App\Support\Money::format($wallet->available_balance)" hint="Spendable now" tone="info" />
        <x-stat label="Held" :value="\App\Support\Money::format($held)" hint="Reserved or locked" tone="warning" />
        <x-stat label="Status" :value="ucfirst($wallet->status)" :hint="$wallet->updated_at?->diffForHumans() ?? '—'" tone="neutral" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Transactions" :subtitle="$transactions->total().' movement(s)'" :padding="false">
                @if ($transactions->isEmpty())
                    <x-empty title="No wallet activity" message="Deposits, withdrawals and transfers appear here." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Date</th>
                                    <th class="px-4 py-2.5">Type</th>
                                    <th class="px-4 py-2.5">Reference</th>
                                    <th class="px-4 py-2.5 text-right">Amount</th>
                                    <th class="px-4 py-2.5 text-right">Balance after</th>
                                    <th class="px-4 py-2.5">Status</th>
                                    <th class="px-4 py-2.5">Journal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($transactions as $transaction)
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $transaction->created_at?->format('d M Y H:i') ?? '—' }}</td>
                                        <td class="px-4 py-2.5">
                                            <p class="font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</p>
                                            @if ($transaction->description)
                                                <p class="text-xs text-slate-400">{{ $transaction->description }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $transaction->reference ?: '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold {{ $transaction->direction === 'credit' ? 'text-emerald-600' : 'text-rose-600' }}">
                                            {{ $transaction->direction === 'credit' ? '+' : '−' }}@money($transaction->amount)
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-700">@money($transaction->balance_after)</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$transaction->status" /></td>
                                        <td class="px-4 py-2.5">
                                            @if ($transaction->journal_entry_id && $can('accounting.view'))
                                                <a href="{{ route('accounting.journals.show', $transaction->journal_entry_id) }}" class="text-xs font-medium text-brand-700 hover:text-brand-800">
                                                    {{ $transaction->journalEntry?->entry_no ?? 'View' }}
                                                </a>
                                            @else
                                                <span class="text-xs text-slate-400">{{ $transaction->journalEntry?->entry_no ?? '—' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-200 px-4 py-3">{{ $transactions->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Wallet details">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$wallet->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Member</dt>
                        <dd class="text-right font-medium text-slate-800">{{ $wallet->member?->full_name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Member number</dt>
                        <dd class="font-medium text-slate-800">{{ $wallet->member?->member_no ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="font-medium text-slate-800">{{ $wallet->member?->phone ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Opened</dt>
                        <dd class="font-medium text-slate-800">{{ $wallet->created_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </x-card>

            @if ($can('wallet.adjust'))
                <x-card title="Quick adjustment">
                    <form method="POST" action="{{ route('wallet.adjust') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="member_id" value="{{ $wallet->member_id }}">
                        <x-select name="direction" label="Direction" :options="['credit' => 'Credit', 'debit' => 'Debit']" required />
                        <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                        <x-select name="counter" label="Counter account" :options="['cash' => 'Cash', 'bank' => 'Bank']" required />
                        <x-input name="description" label="Description" required />
                        <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Post adjustment</button>
                    </form>
                </x-card>
            @endif

            @if ($can('wallet.reconcile'))
                <x-card title="Reconciliation">
                    <p class="text-sm text-slate-600">Compare wallet balances with the wallet liability ledger and completed transactions.</p>
                    <a href="{{ route('wallet.reconcile') }}" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <x-icon name="scale" class="h-4 w-4" /> Open reconciliation
                    </a>
                </x-card>
            @endif
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Wallet reconciliation')

@section('content')
    @php
        $issueCount = count($issues);
        $difference = $ledgerBalance - $walletTotal;
        $reconciled = $issueCount === 0 && $difference === 0;
    @endphp

    <x-page-header title="Wallet reconciliation" subtitle="Compare member wallet balances, completed transactions and the wallet liability ledger.">
        <a href="{{ route('wallet.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All wallets
        </a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Wallet total" :value="\App\Support\Money::format($walletTotal)" hint="Sum of member wallet balances" />
        <x-stat label="Ledger balance" :value="\App\Support\Money::format($ledgerBalance)" hint="Wallet liability control account" tone="info" />
        <x-stat label="Difference" :value="\App\Support\Money::format($difference)" :hint="$difference === 0 ? 'Ledger agrees' : 'Investigate the variance'" :tone="$difference === 0 ? 'brand' : 'danger'" />
        <x-stat label="Issues" :value="number_format($issueCount)" :hint="$reconciled ? 'Ready for sign-off' : 'Resolve before sign-off'" :tone="$reconciled ? 'brand' : 'warning'" />
    </div>

    @if ($reconciled)
        <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <p class="font-semibold">Wallets reconcile</p>
                <p class="mt-0.5 text-emerald-700">Every wallet matches its completed transactions and the ledger control account. You can sign off below.</p>
            </div>
        </div>
    @else
        <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <p class="font-semibold">{{ $issueCount }} issue(s) found</p>
                <p class="mt-0.5 text-amber-700">Resolve wallet or ledger mismatches before recording a reconciliation sign-off.</p>
            </div>
        </div>
    @endif

    <x-card title="Issues" :subtitle="$issueCount.' item(s) need attention'" :padding="false">
        @if ($issueCount === 0)
            <x-empty title="No discrepancies" message="Wallet balances, transactions and the ledger agree." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5">Wallet / member</th>
                            <th class="px-4 py-2.5 text-right">Recorded</th>
                            <th class="px-4 py-2.5 text-right">Expected</th>
                            <th class="px-4 py-2.5 text-right">Difference</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($issues as $issue)
                            @if (($issue['type'] ?? null) === 'ledger_mismatch')
                                <tr class="bg-rose-50/40">
                                    <td class="px-4 py-2.5"><x-badge status="danger" label="Ledger mismatch" /></td>
                                    <td class="px-4 py-2.5 text-slate-600">Wallet liability control account</td>
                                    <td class="px-4 py-2.5 text-right text-slate-700">@money($issue['ledger_balance']) <span class="block text-xs text-slate-400">ledger</span></td>
                                    <td class="px-4 py-2.5 text-right text-slate-700">@money($issue['wallet_total']) <span class="block text-xs text-slate-400">wallets</span></td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-rose-700">@money($issue['ledger_balance'] - $issue['wallet_total'])</td>
                                </tr>
                            @else
                                <tr class="bg-amber-50/40">
                                    <td class="px-4 py-2.5"><x-badge status="warning" label="Wallet vs transactions" /></td>
                                    <td class="px-4 py-2.5">
                                        <a href="{{ route('wallet.show', $issue['wallet_id']) }}" class="font-medium text-slate-800 hover:text-brand-700">Wallet #{{ $issue['wallet_id'] }}</a>
                                        <p class="text-xs text-slate-400">Member #{{ $issue['member_id'] }}</p>
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-slate-700">@money($issue['wallet_balance']) <span class="block text-xs text-slate-400">wallet</span></td>
                                    <td class="px-4 py-2.5 text-right text-slate-700">@money($issue['transaction_total']) <span class="block text-xs text-slate-400">transactions</span></td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-amber-700">@money($issue['wallet_balance'] - $issue['transaction_total'])</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <x-card title="Sign off" subtitle="Sign-off records an audit event; it never changes wallet balances.">
        <form method="POST" action="{{ route('wallet.reconcile.store') }}" class="flex flex-wrap items-center gap-3">
            @csrf
            <button type="submit" @disabled(! $reconciled) class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                <x-icon name="check" class="h-4 w-4" /> Record reconciliation
            </button>
            <p class="text-xs text-slate-500">
                @if ($reconciled)
                    The check runs again on submit and refuses sign-off if anything has changed.
                @else
                    Resolve the issues above to enable sign-off.
                @endif
            </p>
        </form>
    </x-card>
@endsection

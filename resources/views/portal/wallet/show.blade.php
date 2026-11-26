@extends('layouts.portal')

@section('title', 'My wallet')

@section('content')
    @php
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
        $pageCredits = (int) $transactions->getCollection()->where('direction', 'credit')->sum('amount');
        $pageDebits = (int) $transactions->getCollection()->where('direction', 'debit')->sum('amount');
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">My wallet</h1>
        <p class="mt-1 text-sm text-slate-500">Top up with M-Pesa, pay members and track every wallet movement.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Wallet balance" :value="\App\Support\Money::format($wallet->balance)" hint="Total wallet value" />
        <x-stat label="Available" :value="\App\Support\Money::format($wallet->available_balance)" hint="Ready to spend or transfer" tone="info" />
        <x-stat label="Credited (this page)" :value="\App\Support\Money::format($pageCredits)" :hint="$transactions->total().' transaction(s) in total'" tone="neutral" />
        <x-stat label="Debited (this page)" :value="\App\Support\Money::format($pageDebits)" hint="Transfers and payments" tone="warning" />
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="Wallet transactions" :subtitle="$transactions->total().' movement(s)'" :padding="false">
                @if ($transactions->isEmpty())
                    <x-empty title="No wallet activity" message="Top up your wallet to start transacting." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($transactions as $transaction)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">
                                        {{ $transaction->description ?: ucfirst(str_replace('_', ' ', $transaction->type)) }}
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        {{ $transaction->created_at?->format('d M Y H:i') ?? '—' }}
                                        @if ($transaction->provider)
                                            · {{ strtoupper($transaction->provider) }}
                                        @endif
                                        @if ($transaction->reference)
                                            · <span class="font-mono">{{ $transaction->reference }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold {{ $transaction->direction === 'credit' ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ $transaction->direction === 'credit' ? '+' : '-' }}@money($transaction->amount)
                                    </p>
                                    <p class="text-xs text-slate-400">Balance @money($transaction->balance_after)</p>
                                    <x-badge :status="$transaction->status" class="mt-1" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $transactions->links() }}</div>
                @endif
            </x-card>

            <x-card title="Recent top-up requests" subtitle="M-Pesa requests you have initiated." :padding="false">
                @if ($topUps->isEmpty())
                    <x-empty title="No top-up requests" message="Use the form on the right to request a wallet top-up." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($topUps as $intent)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-800">@money($intent->amount) · {{ strtoupper($intent->provider ?? 'M-PESA') }}</p>
                                    <p class="truncate font-mono text-xs text-slate-400">{{ $intent->uuid }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $intent->created_at?->format('d M Y H:i') ?? '—' }}
                                        @if ($intent->phone)
                                            · {{ $intent->phone }}
                                        @endif
                                    </p>
                                    @if ($intent->failure_reason)
                                        <p class="mt-0.5 text-xs text-rose-600">{{ $intent->failure_reason }}</p>
                                    @endif
                                </div>
                                <x-badge :status="$intent->status" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Top up wallet" subtitle="An M-Pesa prompt is sent to the phone number below.">
                <form method="POST" action="{{ route('portal.wallet.top-up') }}" class="space-y-3">
                    @csrf
                    <x-input name="amount" type="number" step="0.01" min="1" label="Amount (KES)" required />
                    <x-input name="phone" type="tel" label="M-Pesa phone" placeholder="07xx xxx xxx" required />
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="wallet" class="h-4 w-4" /> Request top-up
                    </button>
                </form>
            </x-card>

            <x-card title="Pay a member" subtitle="Transfer from your available wallet balance.">
                @if (empty($memberOptions))
                    <p class="text-sm text-slate-500">There are no other active members to pay.</p>
                @else
                    <form method="POST" action="{{ route('portal.wallet.transfer') }}" class="space-y-3">
                        @csrf
                        <x-select name="to_member_id" label="Recipient" :options="$memberOptions" placeholder="Select a member" required />
                        <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount (KES)" required />
                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                            <x-icon name="arrow-right" class="h-4 w-4" /> Send money
                        </button>
                    </form>
                @endif
            </x-card>

            @if (\App\Support\FloatAccess::enabled(app(\App\Support\GroupContext::class)->group()))
                <x-card title="Buy M-Pesa float" subtitle="Recharge an M-Pesa number from your wallet.">
                    <p class="text-sm text-slate-600">Choose a package, enter the recipient number and pay from your available balance. Failed purchases are refunded automatically.</p>
                    <a href="{{ route('portal.float.index') }}" class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-brand-700 hover:text-brand-800">
                        Buy float <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </x-card>
            @endif

            <x-card title="How your wallet works">
                <ul class="space-y-2 text-sm text-slate-600">
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Top-ups are credited only after the M-Pesa callback is verified.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Transfers between members are instant and recorded in the ledger.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Contact the office if a top-up is not reflected after a few minutes.</li>
                </ul>
            </x-card>
        </div>
    </div>
@endsection

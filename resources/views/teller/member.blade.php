@extends('layouts.app')

@section('title', 'Teller · '.$member->full_name)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $canOperate = $can('teller.operate') && $session;
        $savingsTotal = $accounts->sum('balance');
        $loanTotal = $loans->sum(fn ($loan) => $loan->outstandingTotal());
        $chargesFor = fn (string $category) => $applicableCharges
            ->filter(fn ($charge) => $charge->category === $category || $charge->category === '*')
            ->values();
    @endphp

    <x-page-header :title="$member->full_name" :subtitle="$member->member_no.' · '.($member->phone ?: 'No phone').' · joined '.($member->joined_at?->format('d M Y') ?? '—')">
        <a href="{{ route('teller.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Teller desk
        </a>
        <a href="{{ route('members.show', $member) }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
            <x-icon name="users" class="h-4 w-4" /> Member profile
        </a>
    </x-page-header>

    @if ($errors->has('teller'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $errors->first('teller') }}
        </div>
    @endif

    @unless ($session)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            You need an open till before posting transactions.
            <a href="{{ route('teller.index') }}" class="font-semibold underline">Open your till</a>.
        </div>
    @endunless

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Savings" :value="\App\Support\Money::format($savingsTotal)" :hint="$accounts->count().' account(s)'" />
        <x-stat label="Loan outstanding" :value="\App\Support\Money::format($loanTotal)" :hint="$loans->count().' active loan(s)'" tone="warning" />
        <x-stat label="Wallet" :value="\App\Support\Money::format($wallet?->available_balance ?? 0)" hint="Available wallet balance" tone="info" />
        <x-stat label="Investments" :value="\App\Support\Money::format($investments->sum('principal'))" :hint="$investments->count().' open investment(s)'" tone="neutral" />
    </div>

    @if ($canOperate)
        <div class="grid gap-5 xl:grid-cols-2">
            <x-card title="Savings deposit" subtitle="Cash or bank received into a member savings account.">
                <form method="POST" action="{{ route('teller.entries.store') }}" class="grid gap-3 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="member_id" value="{{ $member->id }}">
                    <input type="hidden" name="category" value="savings_deposit">
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <x-teller-charges :charges="$chargesFor('savings_deposit')" />
                    <div class="sm:col-span-2">
                        <x-select name="savings_account_id" id="deposit-account" label="Savings account" :options="$accounts->mapWithKeys(fn ($account) => [$account->id => $account->account_no.' · '.($account->product?->name ?? 'Savings').' · '.\App\Support\Money::format($account->available_balance)])->all()" required placeholder="Select account" />
                    </div>
                    <x-input name="amount" id="deposit-amount" type="number" step="0.01" min="0.01" label="Amount" required />
                    <x-select name="method" id="deposit-method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank', 'mpesa' => 'M-Pesa', 'cheque' => 'Cheque']" required />
                    <x-teller-cheque-fields id-prefix="deposit-cheque" />
                    <x-input name="reference" id="deposit-reference" label="Reference" placeholder="Optional" />
                    <x-input name="narration" id="deposit-narration" label="Narration" placeholder="Optional" />
                    <div class="sm:col-span-2">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            <x-icon name="plus" class="h-4 w-4" /> Post deposit
                        </button>
                    </div>
                </form>
            </x-card>

            @if ($accounts->contains(fn ($account) => $account->product?->allows_withdrawal))
                <x-card title="Savings withdrawal" subtitle="Cash paid out of a member savings account.">
                    <form method="POST" action="{{ route('teller.entries.store') }}" class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="member_id" value="{{ $member->id }}">
                        <input type="hidden" name="category" value="savings_withdrawal">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <x-teller-charges :charges="$chargesFor('savings_withdrawal')" direction="out" />
                        <div class="sm:col-span-2">
                            <x-select name="savings_account_id" id="withdraw-account" label="Savings account" :options="$accounts->filter(fn ($account) => $account->product?->allows_withdrawal)->mapWithKeys(fn ($account) => [$account->id => $account->account_no.' · '.($account->product?->name ?? 'Savings').' · '.\App\Support\Money::format($account->available_balance)])->all()" required placeholder="Select account" />
                        </div>
                        <x-input name="amount" id="withdraw-amount" type="number" step="0.01" min="0.01" label="Amount" required />
                        <x-select name="method" id="withdraw-method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank']" required />
                        <x-input name="reference" id="withdraw-reference" label="Reference" placeholder="Optional" />
                        <x-input name="narration" id="withdraw-narration" label="Narration" placeholder="Optional" />
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                <x-icon name="arrow-right" class="h-4 w-4" /> Post withdrawal
                            </button>
                        </div>
                    </form>
                </x-card>
            @endif

            @if ($loans->isNotEmpty())
                <x-card title="Loan repayment" subtitle="Receive a repayment and allocate it across penalties, fees, interest and principal.">
                    <form method="POST" action="{{ route('teller.entries.store') }}" class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="member_id" value="{{ $member->id }}">
                        <input type="hidden" name="category" value="loan_repayment">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <x-teller-charges :charges="$chargesFor('loan_repayment')" />
                        <div class="sm:col-span-2">
                            <x-select name="loan_id" id="repay-loan" label="Loan" :options="$loans->mapWithKeys(fn ($loan) => [$loan->id => $loan->loan_no.' · '.\App\Support\Money::format($loan->outstandingTotal()).' outstanding'])->all()" required placeholder="Select loan" />
                        </div>
                        <x-input name="amount" id="repay-amount" type="number" step="0.01" min="0.01" label="Amount" required />
                        <x-select name="method" id="repay-method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank', 'mpesa' => 'M-Pesa', 'cheque' => 'Cheque']" required />
                        <x-teller-cheque-fields id-prefix="repay-cheque" />
                        <x-input name="reference" id="repay-reference" label="Reference" placeholder="Optional" />
                        <x-input name="narration" id="repay-narration" label="Narration" placeholder="Optional" />
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                                <x-icon name="check" class="h-4 w-4" /> Post repayment
                            </button>
                        </div>
                    </form>
                </x-card>
            @endif

            @if ($contributions->isNotEmpty())
                <x-card title="Merry-go-round contribution" subtitle="Record a member contribution into the active cycle.">
                    <form method="POST" action="{{ route('teller.entries.store') }}" class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="member_id" value="{{ $member->id }}">
                        <input type="hidden" name="category" value="mgr_contribution">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <x-teller-charges :charges="$chargesFor('mgr_contribution')" />
                        <div class="sm:col-span-2">
                            <x-select name="contribution_id" id="mgr-contribution" label="Contribution" :options="$contributions->mapWithKeys(fn ($contribution) => [$contribution->id => ($contribution->cycle?->name ?? 'Cycle').' · due '.($contribution->due_date?->format('d M Y') ?? '—').' · '.\App\Support\Money::format($contribution->amount)])->all()" required placeholder="Select contribution" />
                        </div>
                        <x-input name="amount" id="mgr-amount" type="number" step="0.01" min="0.01" label="Amount" required help="The scheduled contribution amount is used for the entry." />
                        <x-select name="method" id="mgr-method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank', 'mpesa' => 'M-Pesa', 'cheque' => 'Cheque']" required />
                        <x-teller-cheque-fields id-prefix="mgr-cheque" />
                        <x-input name="reference" id="mgr-reference" label="Reference" placeholder="Optional" />
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                <x-icon name="calendar" class="h-4 w-4" /> Post contribution
                            </button>
                        </div>
                    </form>
                </x-card>
            @endif

            @if ($investmentProducts->isNotEmpty())
                <x-card title="Investment placement" subtitle="Receive cash or bank funds and open an investment for the member.">
                    <form method="POST" action="{{ route('teller.entries.store') }}" class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="member_id" value="{{ $member->id }}">
                        <input type="hidden" name="category" value="investment_place">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <x-teller-charges :charges="$chargesFor('investment_place')" />
                        <div class="sm:col-span-2">
                            <x-select name="investment_product_id" id="invest-product" label="Investment product" :options="$investmentProducts->mapWithKeys(fn ($product) => [$product->id => $product->name.' · '.\App\Support\Money::format($product->min_amount).' – '.\App\Support\Money::format($product->max_amount)])->all()" required placeholder="Select product" />
                        </div>
                        <x-input name="amount" id="invest-amount" type="number" step="0.01" min="0.01" label="Amount" required />
                        <x-select name="method" id="invest-method" label="Funding" :options="['cash' => 'Cash', 'bank' => 'Bank', 'cheque' => 'Cheque']" required />
                        <x-teller-cheque-fields id-prefix="invest-cheque" />
                        <x-input name="reference" id="invest-reference" label="Reference" placeholder="Optional" />
                        <x-input name="narration" id="invest-narration" label="Narration" placeholder="Optional" />
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                                <x-icon name="trending-up" class="h-4 w-4" /> Place investment
                            </button>
                        </div>
                    </form>
                </x-card>
            @endif

            @if ($investments->isNotEmpty())
                <x-card title="Investment payout" subtitle="Pay out a matured or withdrawn investment in cash or to the bank.">
                    <form method="POST" action="{{ route('teller.entries.store') }}" class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="member_id" value="{{ $member->id }}">
                        <input type="hidden" name="category" value="investment_payout">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <x-teller-charges :charges="$chargesFor('investment_payout')" direction="out" />
                        <div class="sm:col-span-2">
                            <x-select name="investment_id" id="payout-investment" label="Investment" :options="$investments->mapWithKeys(fn ($investment) => [$investment->id => $investment->reference_no.' · '.($investment->product?->name ?? 'Investment').' · value '.\App\Support\Money::format($investment->totalValue())])->all()" required placeholder="Select investment" />
                        </div>
                        <x-input name="amount" id="payout-amount" type="number" step="0.01" min="0.01" label="Amount" required help="The calculated payout amount is used for the entry." />
                        <x-select name="method" id="payout-method" label="Destination" :options="['cash' => 'Cash', 'bank' => 'Bank']" required />
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                <x-icon name="arrow-right" class="h-4 w-4" /> Pay out
                            </button>
                        </div>
                    </form>
                </x-card>
            @endif

            <x-card title="Wallet" subtitle="Take a cash top-up into the member wallet or pay cash out of it.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <form method="POST" action="{{ route('teller.entries.store') }}" class="grid gap-3">
                        @csrf
                        <input type="hidden" name="member_id" value="{{ $member->id }}">
                        <input type="hidden" name="category" value="wallet_deposit">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <x-teller-charges :charges="$chargesFor('wallet_deposit')" />
                        <x-input name="amount" id="wallet-in-amount" type="number" step="0.01" min="0.01" label="Top-up amount" required />
                        <x-select name="method" id="wallet-in-method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank', 'mpesa' => 'M-Pesa', 'cheque' => 'Cheque']" required />
                        <x-teller-cheque-fields id-prefix="wallet-in-cheque" />
                        <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            <x-icon name="plus" class="h-4 w-4" /> Wallet top-up
                        </button>
                    </form>

                    <form method="POST" action="{{ route('teller.entries.store') }}" class="grid gap-3">
                        @csrf
                        <input type="hidden" name="member_id" value="{{ $member->id }}">
                        <input type="hidden" name="category" value="wallet_withdrawal">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <x-teller-charges :charges="$chargesFor('wallet_withdrawal')" direction="out" />
                        <x-input name="amount" id="wallet-out-amount" type="number" step="0.01" min="0.01" label="Withdrawal amount" required />
                        <x-select name="method" id="wallet-out-method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank']" required />
                        <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                            <x-icon name="arrow-right" class="h-4 w-4" /> Wallet withdrawal
                        </button>
                    </form>
                </div>
            </x-card>
        </div>
    @endif

    <x-card title="This member's teller history" :subtitle="$entries->count().' recent entry(ies)'" :padding="false">
        @if ($entries->isEmpty())
            <x-empty title="No teller entries" message="Transactions posted for this member will appear here." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">Receipt</th>
                            <th class="px-4 py-2.5">Date</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5">Method</th>
                            <th class="px-4 py-2.5 text-right">Amount</th>
                            <th class="px-4 py-2.5 text-right">Charge</th>
                            <th class="px-4 py-2.5 text-right">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($entries as $entry)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-800">{{ $entry->receipt_no }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $entry->created_at?->format('d M Y H:i') }}</td>
                                <td class="px-4 py-2.5 text-slate-700">{{ $entry->categoryLabel() }}</td>
                                <td class="px-4 py-2.5 capitalize text-slate-600">
                                    {{ $entry->method }}
                                    @if ($entry->isCheque())
                                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">{{ $entry->statusLabel() }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold {{ $entry->direction === 'in' ? 'text-emerald-600' : 'text-rose-600' }}">
                                    {{ $entry->direction === 'in' ? '+' : '−' }}@money($entry->amount)
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-600">{{ $entry->charge_amount > 0 ? \App\Support\Money::format($entry->charge_amount) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <a href="{{ route('teller.entries.receipt', $entry) }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800">Print</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endsection

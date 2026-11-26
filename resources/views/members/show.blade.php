@extends('layouts.app')

@section('title', $member->full_name)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $fineOutstanding = $fines->sum(fn ($fine) => $fine->outstanding());
        $walletBalance = (int) ($member->wallet?->balance ?? 0);
        $guaranteedTotal = (int) $member->guarantees->sum('guaranteed_amount');
        $biometricCaptured = $member->documents->contains(
            fn ($document) => str_starts_with(strtolower((string) $document->type), 'biometric')
                && $document->status !== 'rejected'
        );
    @endphp

    <x-page-header :title="$member->full_name" :subtitle="$member->member_no.' · '.($member->role?->name ?? 'No role').' · joined '.($member->joined_at?->format('d M Y') ?? 'not yet')">
        @can('update', $member)
            <a href="{{ route('members.edit', $member) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Edit details
            </a>
        @endcan
        <a href="{{ route('members.index') }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
            <x-icon name="arrow-left" class="h-4 w-4" /> All members
        </a>
    </x-page-header>

    @if ($member->status !== 'active' || $member->kyc_status !== 'verified')
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <div>
                <p class="font-medium">This membership is {{ $member->status }} and KYC is {{ $member->kyc_status }}.</p>
                @if ($member->rejection_reason)
                    <p class="mt-1 text-xs">Reason: {{ $member->rejection_reason }}</p>
                @endif
            </div>
            @can('approve', $member)
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('members.approve', $member) }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        @if (! empty($missingDocuments))
                            <label class="flex items-center gap-1.5 text-[11px] font-medium text-amber-900">
                                <input type="checkbox" name="override_documents" value="1" class="h-3.5 w-3.5 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                                <span>Approve without required documents</span>
                            </label>
                        @endif
                        <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Approve &amp; open accounts</button>
                    </form>
                    <details class="relative">
                        <summary class="cursor-pointer list-none rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Reject</summary>
                        <form method="POST" action="{{ route('members.reject', $member) }}" class="absolute right-0 z-20 mt-2 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                            @csrf
                            <x-textarea name="reason" label="Rejection reason" rows="3" required />
                            <button type="submit" class="mt-2 w-full rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Confirm rejection</button>
                        </form>
                    </details>
                </div>
            @endcan
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Savings" :value="\App\Support\Money::format($savingsTotal)" hint="Across all savings products" />
        <x-stat label="Loan balance" :value="\App\Support\Money::format($loanBalance)" :hint="$member->activeLoans->count().' active loan(s)'" tone="info" />
        <x-stat label="Wallet" :value="\App\Support\Money::format($walletBalance)" :hint="$walletBalance > 0 ? 'Available for transfers' : 'No wallet float'" tone="neutral" />
        <x-stat label="Fines due" :value="\App\Support\Money::format($fineOutstanding)" :hint="$fines->count().' unsettled fine(s)'" :tone="$fineOutstanding > 0 ? 'warning' : 'brand'" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Savings accounts" :padding="false">
                @if ($member->savingsAccounts->isEmpty())
                    <x-empty title="No savings accounts" message="Accounts are opened automatically when a member is approved." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Account</th>
                                    <th class="px-4 py-2.5">Product</th>
                                    <th class="px-4 py-2.5 text-right">Balance</th>
                                    <th class="px-4 py-2.5 text-right">Available</th>
                                    <th class="px-4 py-2.5">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($member->savingsAccounts as $account)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-medium text-slate-800">
                                            <a href="{{ route('savings.show', $account) }}" class="hover:text-brand-700">{{ $account->account_no }}</a>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $account->product?->name ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">@money($account->balance)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($account->available_balance)</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$account->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card title="Active loans" :padding="false">
                @if ($member->activeLoans->isEmpty())
                    <x-empty title="No active loans" message="Loan applications and running facilities appear here." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Loan</th>
                                    <th class="px-4 py-2.5">Product</th>
                                    <th class="px-4 py-2.5 text-right">Principal</th>
                                    <th class="px-4 py-2.5 text-right">Outstanding</th>
                                    <th class="px-4 py-2.5">Progress</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($member->activeLoans as $loan)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-medium text-slate-800">
                                            <a href="{{ route('loans.show', $loan) }}" class="hover:text-brand-700">{{ $loan->loan_no }}</a>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $loan->product?->name ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($loan->principal)</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">@money($loan->outstandingTotal())</td>
                                        <td class="px-4 py-2.5">
                                            <div class="flex items-center gap-2">
                                                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200">
                                                    <div class="h-full rounded-full bg-brand-500" style="width: {{ $loan->progressPercent() }}%"></div>
                                                </div>
                                                <span class="text-xs text-slate-500">{{ $loan->progressPercent() }}%</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card title="Guarantees given" :padding="false">
                @if ($member->guarantees->isEmpty())
                    <x-empty title="No guarantees" message="This member has not guaranteed any loans." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Loan</th>
                                    <th class="px-4 py-2.5">Borrower</th>
                                    <th class="px-4 py-2.5 text-right">Guaranteed</th>
                                    <th class="px-4 py-2.5">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($member->guarantees as $guarantee)
                                    <tr>
                                        <td class="px-4 py-2.5 font-medium text-slate-800">
                                            <a href="{{ route('loans.show', $guarantee->loan) }}" class="hover:text-brand-700">{{ $guarantee->loan?->loan_no }}</a>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $guarantee->loan?->member?->full_name ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">@money($guarantee->guaranteed_amount)</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$guarantee->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card title="Fines" :padding="false">
                @if ($fines->isEmpty())
                    <x-empty title="No outstanding fines" class="m-4" />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($fines as $fine)
                            <li class="flex items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <p class="text-sm font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $fine->type)) }}</p>
                                    <p class="text-xs text-slate-500">{{ $fine->reason ?: '—' }} · due {{ $fine->due_date?->format('d M Y') ?? '—' }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-slate-900">@money($fine->outstanding())</p>
                                    <x-badge :status="$fine->status" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Membership">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$member->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">KYC</dt>
                        <dd><x-badge :status="$member->kyc_status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Role</dt>
                        <dd class="font-medium text-slate-800">{{ $member->role?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Category</dt>
                        <dd class="font-medium text-slate-800">{{ ucfirst($member->membership_category ?? 'ordinary') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Biometric</dt>
                        <dd class="text-right text-xs font-semibold {{ $biometricCaptured ? 'text-emerald-700' : ($member->biometricRequired() ? 'text-amber-700' : 'text-slate-500') }}">
                            {{ $biometricCaptured ? 'Captured' : ($member->biometricRequired() ? 'Required · not captured' : 'Optional') }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Guarantees</dt>
                        <dd class="font-medium text-slate-800">@money($guaranteedTotal)</dd>
                    </div>
                </dl>

                @can('terminate', $member)
                    <details class="mt-4 border-t border-slate-100 pt-3">
                        <summary class="cursor-pointer text-xs font-semibold text-rose-600">Terminate membership</summary>
                        <form method="POST" action="{{ route('members.terminate', $member) }}" class="mt-2 space-y-2">
                            @csrf
                            <x-textarea name="reason" label="Reason" rows="2" required />
                            <button type="submit" class="w-full rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Terminate</button>
                        </form>
                    </details>
                @endcan
            </x-card>

            <x-card title="Contact">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="font-medium text-slate-800">{{ $member->phone ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Email</dt>
                        <dd class="break-all font-medium text-slate-800">{{ $member->email ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">National ID</dt>
                        <dd class="font-medium text-slate-800">{{ $member->national_id ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Address</dt>
                        <dd class="font-medium text-slate-800">{{ $member->address ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Occupation</dt>
                        <dd class="font-medium text-slate-800">{{ $member->occupation ?: '—' }}{{ $member->employer ? ' · '.$member->employer : '' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Workstation / work location</dt>
                        <dd class="font-medium text-slate-800">{{ $member->workstation ?: '—' }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Next of kin">
                @if ($member->nextOfKin->isEmpty())
                    <p class="text-sm text-slate-500">No next of kin recorded.</p>
                @else
                    <ul class="space-y-3 text-sm">
                        @foreach ($member->nextOfKin as $kin)
                            <li>
                                <p class="font-medium text-slate-800">{{ $kin->name }}</p>
                                <p class="text-xs text-slate-500">{{ $kin->relationship }} · {{ $kin->phone }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Beneficiaries">
                @if ($member->beneficiaries->isEmpty())
                    <p class="text-sm text-slate-500">No beneficiaries recorded.</p>
                @else
                    <ul class="space-y-3 text-sm">
                        @foreach ($member->beneficiaries as $beneficiary)
                            <li>
                                <p class="font-medium text-slate-800">
                                    {{ $beneficiary->name }}
                                    @if ($beneficiary->is_primary)
                                        <span class="ml-1 rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-700">Primary</span>
                                    @endif
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $beneficiary->relationship }} · {{ $beneficiary->allocation_percent }}%{{ $beneficiary->phone ? ' · '.$beneficiary->phone : '' }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Documents">
                @if ($member->documents->isEmpty())
                    <p class="text-sm text-slate-500">No documents uploaded.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($member->documents as $document)
                            <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                <a href="{{ route('documents.show', $document) }}" class="min-w-0 truncate font-medium text-slate-800 hover:text-brand-700">{{ $document->title }}</a>
                                <x-badge :status="$document->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection

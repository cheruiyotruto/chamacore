@extends('layouts.portal')

@section('title', 'My loans')

@section('content')
    @php
        $pendingGuarantees = $guaranteeRequests->count();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">My loans</h1>
            <p class="mt-1 text-sm text-slate-500">Track applications, guarantor requests and active loan repayments.</p>
        </div>
        <a href="{{ route('portal.loans.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="plus" class="h-4 w-4" /> Apply for loan
        </a>
    </div>

    @if ($pendingGuarantees > 0)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">You have {{ $pendingGuarantees }} pending guarantee request(s). Accept or reject them below.</p>
        </div>
    @endif

    @if ($awaitingSavingsGuarantees->isNotEmpty())
        <x-card title="Fund your guarantee"
                subtitle="Deposit qualifying savings to release these guarantee requests."
                :padding="false"
                class="mb-6">
            <div class="divide-y divide-slate-100">
                @foreach ($awaitingSavingsGuarantees as $guarantor)
                    <div id="guarantee-savings-{{ $guarantor->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 scroll-mt-20">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800">
                                {{ $guarantor->loan?->loan_no ?? 'Loan #'.$guarantor->loan_id }}
                                · {{ $guarantor->loan->member?->full_name ?? 'Borrower' }}
                            </p>
                            <p class="text-xs text-slate-500">
                                Guarantee @money($guarantor->guaranteed_amount)
                                · Deposit at least @money($guarantor->required_savings_amount) in qualifying savings
                            </p>
                        </div>
                        <a href="{{ route('portal.savings.index') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">
                            Go to savings
                        </a>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    @if ($guaranteeRequests->isNotEmpty())
        <x-card title="Guarantee requests" subtitle="Decide on loans others are asking you to guarantee." :padding="false" class="mb-6">
            <div class="divide-y divide-slate-100">
                @foreach ($guaranteeRequests as $guarantor)
                    <div id="guarantee-{{ $guarantor->id }}" class="flex flex-wrap items-center justify-between gap-3 scroll-mt-20 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800">
                                {{ $guarantor->loan?->loan_no ?? 'Loan #'.$guarantor->loan_id }}
                                · {{ $guarantor->loan->member?->full_name ?? 'Borrower' }}
                            </p>
                            <p class="text-xs text-slate-500">Requested {{ $guarantor->requested_at?->format('d M Y') ?? '—' }} · Guaranteed amount: @money($guarantor->guaranteed_amount) · Your capacity at request: @money($guarantor->capacity_at_request)</p>
                            @if ($guarantor->coverage_basis === \App\Support\GuaranteeSettings::COVERAGE_FULL_OBLIGATION)
                                <p class="mt-0.5 text-xs font-medium text-emerald-700">Covers principal, interest and fees</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <details class="relative">
                                <summary class="cursor-pointer list-none rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Accept</summary>
                                <form method="POST" action="{{ route('portal.guarantees.respond', $guarantor) }}" class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                                    @csrf
                                    <input type="hidden" name="decision" value="accept">

                                    @if ($requireGuaranteeSignature)
                                        <p class="text-xs text-slate-500">After accepting, you will be asked to add your electronic signature.</p>
                                    @endif

                                    <x-textarea name="note" label="Note (optional)" rows="2" />
                                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Accept guarantee</button>
                                </form>
                            </details>
                            <details class="relative">
                                <summary class="cursor-pointer list-none rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Reject</summary>
                                <form method="POST" action="{{ route('portal.guarantees.respond', $guarantor) }}" class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                                    @csrf
                                    <input type="hidden" name="decision" value="reject">
                                    <x-textarea name="note" label="Note (optional)" rows="2" />
                                    <button type="submit" class="w-full rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Reject guarantee</button>
                                </form>
                            </details>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    @if ($requireGuaranteeSignature && $acceptedGuarantees->isNotEmpty())
        <x-card title="Awaiting your signature"
                subtitle="You accepted these guarantees. Add your e-signature to complete them."
                :padding="false"
                class="mb-6">
            <div class="divide-y divide-slate-100">
                @foreach ($acceptedGuarantees as $guarantor)
                    <div id="guarantee-signature-{{ $guarantor->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 scroll-mt-20">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800">
                                {{ $guarantor->loan?->loan_no ?? 'Loan #'.$guarantor->loan_id }}
                                · {{ $guarantor->loan->member?->full_name ?? 'Borrower' }}
                            </p>
                            <p class="text-xs text-slate-500">Accepted {{ $guarantor->responded_at?->format('d M Y') ?? '—' }} · Guaranteed amount: @money($guarantor->guaranteed_amount)</p>
                        </div>
                        <a href="{{ route('portal.guarantees.sign', $guarantor) }}" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">
                            Sign guarantee
                        </a>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    <x-card title="Loans" subtitle="{{ $loans->total() }} loan(s)" :padding="false">
        @if ($loans->isEmpty())
            <x-empty title="No loans" message="You have not applied for a loan yet." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Loan</th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3 text-right">Requested</th>
                            <th class="px-4 py-3 text-right">Approved</th>
                            <th class="px-4 py-3 text-right">Outstanding</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Applied</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($loans as $loan)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('portal.loans.show', $loan) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $loan->loan_no }}</a>
                                    <p class="text-xs text-slate-400">{{ $loan->duration }} {{ $loan->duration_unit }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ $loan->product?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">@money($loan->requested_amount)</td>
                                <td class="px-4 py-3 text-right text-slate-700">@money($loan->approved_amount)</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">
                                    @if ($loan->isActive())
                                        @money($loan->outstandingTotal())
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3"><x-badge :status="$loan->status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $loan->created_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right"><a href="{{ route('portal.loans.show', $loan) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $loans->links() }}</div>
        @endif
    </x-card>
@endsection

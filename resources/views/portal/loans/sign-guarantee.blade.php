@extends('layouts.portal')

@section('title', 'Sign guarantee')

@section('content')
    <div class="mb-6">
        <a href="{{ route('portal.loans.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-700">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to my loans
        </a>
        <h1 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">Sign guarantee</h1>
        <p class="mt-1 text-sm text-slate-500">Add your electronic signature to complete the guarantee you accepted.</p>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <x-card title="Guarantee details" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Loan</dt>
                    <dd class="mt-0.5 font-medium text-slate-800">{{ $guarantee->loan?->loan_no ?? 'Loan #'.$guarantee->loan_id }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Borrower</dt>
                    <dd class="mt-0.5 text-slate-700">{{ $guarantee->loan->member?->full_name ?? 'Borrower' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Guaranteed amount</dt>
                    <dd class="mt-0.5 font-semibold text-slate-900">@money($guarantee->guaranteed_amount)</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Accepted</dt>
                    <dd class="mt-0.5 text-slate-700">{{ $guarantee->responded_at?->format('d M Y H:i') ?? '—' }}</dd>
                </div>
            </dl>
        </x-card>

        <x-card title="Electronic signature" class="lg:col-span-2">
            @if ($alreadySigned)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    This guarantee has already been signed. Your signature is stored in the group EDMS.
                </div>
            @else
                <form method="POST" action="{{ route('portal.guarantees.sign.store', $guarantee) }}" class="space-y-4">
                    @csrf

                    <x-signature-pad label="Draw your signature"
                                      help="Draw your usual signature in the box using a mouse, stylus or finger."
                                      :required="true" />

                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('portal.loans.index') }}" class="rounded-lg border border-slate-300 px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
                        <button type="submit" class="rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                            Submit signature
                        </button>
                    </div>
                </form>
            @endif
        </x-card>
    </div>
@endsection

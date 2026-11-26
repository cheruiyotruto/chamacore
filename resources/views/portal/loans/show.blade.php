@extends('layouts.portal')

@section('title', $loan->loan_no)

@section('content')
    @php
        $progress = $loan->progressPercent();
        $nextDue = $loan->schedules->firstWhere('status', '!=', 'paid') ?? $loan->nextDueSchedule();
        $paidSchedules = $loan->schedules->where('status', 'paid')->count();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ $loan->loan_no }}</h1>
                <x-badge :status="$loan->status" />
            </div>
            <p class="mt-1 text-sm text-slate-500">
                {{ $loan->product?->name ?? 'Loan' }} · applied {{ $loan->created_at?->format('d M Y') ?? '—' }}
                @if ($loan->maturity_date)
                    · matures {{ $loan->maturity_date->format('d M Y') }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('portal.loans.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="arrow-left" class="h-4 w-4" /> All loans
            </a>
            <a href="{{ route('portal.loans.schedule', $loan) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4 w-4" /> Schedule CSV
            </a>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Approved amount" :value="\App\Support\Money::format($loan->approved_amount ?: $loan->requested_amount)" :hint="$loan->installment_count.' installment(s)'" />
        <x-stat label="Total repayable" :value="\App\Support\Money::format($loan->total_repayable)" :hint="'Interest '.($loan->interest_rate_bps ? \App\Support\Money::percentFromBps($loan->interest_rate_bps) : '0%')" tone="info" />
        <x-stat label="Outstanding" :value="\App\Support\Money::format($loan->outstandingTotal())" :hint="$paidSchedules.' of '.$loan->schedules->count().' installments settled'" tone="warning" />
        <x-stat label="Paid to date" :value="\App\Support\Money::format($loan->amount_paid)" :hint="$progress.'% of total repaid'" tone="brand" />
    </div>

    <x-card class="mt-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-slate-800">Repayment progress</p>
            <p class="text-sm text-slate-500">{{ $progress }}%</p>
        </div>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full {{ $progress >= 100 ? 'bg-emerald-500' : 'bg-brand-500' }}" style="width: {{ max(2, min(100, $progress)) }}%"></div>
        </div>
        <div class="mt-3 grid gap-3 text-xs text-slate-500 sm:grid-cols-3">
            <p>Principal outstanding: <span class="font-medium text-slate-800">@money($loan->outstanding_principal)</span></p>
            <p>Interest outstanding: <span class="font-medium text-slate-800">@money($loan->outstanding_interest)</span></p>
            <p>Fees &amp; penalties: <span class="font-medium text-slate-800">@money($loan->outstanding_fees + $loan->outstanding_penalties)</span></p>
        </div>
    </x-card>

    @if ($loan->requiresEmployerFinance())
        <div class="mt-5">
            <x-card title="Employer finance approval"
                    subtitle="Your employer's finance office must stamp and confirm this loan before it can be disbursed.">
                @php
                    $financeSettings = \App\Support\EmployerFinance::for($currentGroup ?? null);
                    $financeStatus = $loan->employer_finance_status;
                @endphp

                <div class="flex flex-wrap items-center gap-2">
                    <x-badge :status="$financeStatus === \App\Support\EmployerFinance::STATUS_CONFIRMED ? 'approved' : ($financeStatus === \App\Support\EmployerFinance::STATUS_DECLINED ? 'rejected' : 'pending')"
                             :label="$loan->employerFinanceStatusLabel()" />
                    @if ($loan->employer_finance_confirmed_at)
                        <span class="text-xs text-slate-500">Confirmed {{ $loan->employer_finance_confirmed_at->format('d M Y H:i') }}</span>
                    @endif
                </div>

                <p class="mt-3 text-sm text-slate-600">{{ $financeSettings->instructions() }}</p>

                @if ($advanceRequirements && ! $advanceRequirements['complete'])
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <p class="font-semibold">Complete your advance loan documents before visiting the finance office:</p>
                        <ul class="mt-1 list-disc pl-4">
                            @unless ($advanceRequirements['payslips'])<li>Payslip(s)</li>@endunless
                            @unless ($advanceRequirements['employment_letter'])<li>Employment letter</li>@endunless
                            @unless ($advanceRequirements['signature'])<li>Online signature</li>@endunless
                        </ul>
                    </div>
                @endif

                @if ($financeSettings->officeName() || $financeSettings->email() || $financeSettings->phone())
                    <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                        <p class="font-semibold text-slate-700">Finance office</p>
                        @if ($financeSettings->officeName())
                            <p>{{ $financeSettings->officeName() }}</p>
                        @endif
                        @if ($financeSettings->contactName())
                            <p>Contact: {{ $financeSettings->contactName() }}</p>
                        @endif
                        <p>
                            @if ($financeSettings->email()){{ $financeSettings->email() }}@endif
                            @if ($financeSettings->email() && $financeSettings->phone()) · @endif
                            @if ($financeSettings->phone()){{ $financeSettings->phone() }}@endif
                        </p>
                    </div>
                @endif

                @if ($financeStatus === \App\Support\EmployerFinance::STATUS_DECLINED && $loan->employer_finance_notes)
                    <p class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {{ $loan->employer_finance_notes }}
                    </p>
                @endif

                @if ($financeStatus !== \App\Support\EmployerFinance::STATUS_CONFIRMED)
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 p-3">
                            <p class="text-sm font-semibold text-slate-800">1. Download the form</p>
                            <p class="mt-1 text-xs text-slate-500">Print the pre-filled form and take it to your employer's finance office for stamping and signature.</p>
                            <a href="{{ route('portal.loans.employer-finance.form', $loan) }}"
                               class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                                <x-icon name="download" class="h-4 w-4" /> Download form (PDF)
                            </a>
                        </div>

                        <div class="rounded-lg border border-slate-200 p-3">
                            <p class="text-sm font-semibold text-slate-800">2. Upload the stamped copy</p>
                            <p class="mt-1 text-xs text-slate-500">Attach a clear PDF or photo of the stamped and signed form.</p>
                            <form method="POST" action="{{ route('portal.loans.employer-finance.upload', $loan) }}" enctype="multipart/form-data" class="mt-3 space-y-2">
                                @csrf
                                <input type="file" name="stamped_form" accept=".pdf,.jpg,.jpeg,.png,.webp" required
                                       class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-brand-700">
                                <label class="flex items-start gap-2 text-xs text-slate-600">
                                    <input type="checkbox" name="consent" value="1" required class="mt-0.5 h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <span>I confirm the attached form was stamped and signed by my employer's finance office.</span>
                                </label>
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    <x-icon name="upload" class="h-4 w-4" /> Upload stamped form
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                        The employer finance office confirmed this approval. The loan can now proceed to disbursement.
                    </div>
                @endif
            </x-card>
        </div>
    @endif

    @if ($loan->isActive())
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <x-card title="Early settlement">
                <p class="text-sm text-slate-600">Settle this loan in full today for:</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">@money($payoff)</p>
                <p class="mt-1 text-xs text-slate-500">The payoff amount includes outstanding principal, interest, fees and any penalties to date.</p>
            </x-card>
            <x-card title="Next repayment">
                @if ($nextDue)
                    <dl class="space-y-2 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Installment</dt>
                            <dd class="font-medium text-slate-800">#{{ $nextDue->installment_no }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Due date</dt>
                            <dd class="font-medium text-slate-800">{{ $nextDue->due_date?->format('d M Y') ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Amount due</dt>
                            <dd class="font-semibold text-slate-900">@money($nextDue->outstanding())</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Status</dt>
                            <dd><x-badge :status="$nextDue->status" /></dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-slate-600">No installments are outstanding on this loan.</p>
                @endif
            </x-card>
        </div>
    @endif

    <div class="mt-6" x-data="{ tab: 'schedule' }">
        <div class="mb-3 flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
            @foreach (['schedule' => 'Schedule', 'payments' => 'Payments', 'guarantors' => 'Guarantors', 'approvals' => 'Approvals', 'details' => 'Details'] as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'" class="rounded-lg px-3 py-1.5 text-sm font-medium">
                    {{ $label }}
                    @if ($key === 'payments' && $loan->payments->isNotEmpty())
                        <span class="ml-1 text-xs opacity-75">{{ $loan->payments->count() }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div x-show="tab === 'schedule'" x-cloak>
            <x-card title="Repayment schedule" :subtitle="$loan->schedules->count().' installment(s)'" :padding="false">
                @if ($loan->schedules->isEmpty())
                    <x-empty title="No schedule" message="The repayment schedule is generated when the loan is approved." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">Due date</th>
                                    <th class="px-4 py-3 text-right">Principal</th>
                                    <th class="px-4 py-3 text-right">Interest</th>
                                    <th class="px-4 py-3 text-right">Fees</th>
                                    <th class="px-4 py-3 text-right">Penalty</th>
                                    <th class="px-4 py-3 text-right">Total due</th>
                                    <th class="px-4 py-3 text-right">Outstanding</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($loan->schedules as $schedule)
                                    <tr class="{{ $schedule->isOverdue() ? 'bg-rose-50/50' : 'hover:bg-slate-50' }}">
                                        <td class="px-4 py-2.5 font-medium text-slate-700">{{ $schedule->installment_no }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $schedule->due_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right text-slate-700">@money($schedule->principal_due)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-700">@money($schedule->interest_due)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-700">@money($schedule->fee_due)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-700">@money($schedule->penaltyDueExtra())</td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($schedule->totalDue())</td>
                                        <td class="px-4 py-2.5 text-right text-slate-700">@money($schedule->outstanding())</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$schedule->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'payments'" x-cloak>
            <x-card title="Payments made" :subtitle="$loan->payments->count().' payment(s)'" :padding="false">
                @if ($loan->payments->isEmpty())
                    <x-empty title="No payments" message="Repayments will appear here once received." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Paid</th>
                                    <th class="px-4 py-3">Method</th>
                                    <th class="px-4 py-3">Reference</th>
                                    <th class="px-4 py-3 text-right">Amount</th>
                                    <th class="px-4 py-3 text-right">Allocated</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($loan->payments as $payment)
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $payment->paid_at?->format('d M Y H:i') ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-slate-700">{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</td>
                                        <td class="px-4 py-2.5 font-mono text-xs text-slate-400">{{ $payment->reference ?: '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($payment->amount)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">
                                            @if (is_array($payment->allocation) && $payment->allocation)
                                                {{ count($payment->allocation) }} line(s)
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5"><x-badge :status="$payment->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'guarantors'" x-cloak>
            <x-card title="Guarantors" :subtitle="$loan->guarantors->count().' guarantor(s)'" :padding="false">
                @if ($loan->guarantors->isEmpty())
                    <x-empty title="No guarantors" message="This loan has no guarantors attached." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($loan->guarantors as $guarantor)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-800">{{ $guarantor->inviteeLabel() }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $guarantor->guarantor?->member_no ?? 'Invited' }} · guaranteed @money($guarantor->guaranteed_amount)
                                        @if ($guarantor->required_savings_amount)
                                            · deposit @money($guarantor->required_savings_amount)
                                        @endif
                                    </p>
                                    @if ($guarantor->response_note)
                                        <p class="mt-1 text-xs text-slate-400">“{{ $guarantor->response_note }}”</p>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <x-badge :status="$guarantor->status" />
                                    <p class="mt-1 text-xs text-slate-400">
                                        @if ($guarantor->responded_at)
                                            Responded {{ $guarantor->responded_at->format('d M Y') }}
                                        @else
                                            Awaiting response
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'approvals'" x-cloak>
            <x-card title="Approval trail" :subtitle="$loan->approvals->count().' step(s)'" :padding="false">
                @if ($loan->approvals->isEmpty())
                    <x-empty title="No approvals yet" message="Approval steps are recorded as the loan moves through the workflow." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($loan->approvals as $approval)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <p class="text-sm font-medium text-slate-800">Step {{ $approval->step_index + 1 }} · {{ ucfirst(str_replace('_', ' ', $approval->role_key)) }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $approval->actor?->name ?? 'Pending' }}
                                        @if ($approval->acted_at)
                                            · {{ $approval->acted_at->format('d M Y H:i') }}
                                        @endif
                                    </p>
                                    @if ($approval->comments)
                                        <p class="mt-1 text-xs text-slate-400">{{ $approval->comments }}</p>
                                    @endif
                                </div>
                                <x-badge :status="$approval->action" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'details'" x-cloak>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-card title="Loan terms">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Product</dt>
                            <dd class="font-medium text-slate-800">{{ $loan->product?->name ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Interest method</dt>
                            <dd class="font-medium text-slate-800">{{ $loan->product?->methodLabel() ?? ucfirst(str_replace('_', ' ', $loan->interest_method)) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Interest rate</dt>
                            <dd class="font-medium text-slate-800">{{ $loan->interest_rate_bps ? \App\Support\Money::percentFromBps($loan->interest_rate_bps).' / '.$loan->interest_period : '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Duration</dt>
                            <dd class="font-medium text-slate-800">{{ $loan->duration }} {{ $loan->duration_unit }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Repayment</dt>
                            <dd class="font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $loan->repayment_frequency ?? '—')) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">First due date</dt>
                            <dd class="font-medium text-slate-800">{{ $loan->first_due_date?->format('d M Y') ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Disbursed</dt>
                            <dd class="font-medium text-slate-800">{{ $loan->disbursed_at?->format('d M Y') ?? 'Not yet' }}</dd>
                        </div>
                    </dl>
                </x-card>

                <x-card title="Purpose">
                    @if ($loan->purpose)
                        <p class="text-sm font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $loan->purpose)) }}</p>
                    @else
                        <p class="text-sm text-slate-500">No purpose recorded.</p>
                    @endif
                    @if ($loan->purpose_description)
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $loan->purpose_description }}</p>
                    @endif
                    @php
                        $advanceApp = data_get($loan->terms_snapshot, 'advance_application', []);
                    @endphp
                    @if (! empty($advanceApp))
                        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                            <p class="font-semibold text-slate-800">Advance / employment details</p>
                            <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                                @foreach ([
                                    'kra_pin' => 'KRA PIN',
                                    'payroll_number' => 'Payroll / staff no.',
                                    'department' => 'Department / station',
                                    'job_title' => 'Job title / designation',
                                    'employment_terms' => 'Employment terms',
                                    'contract_expiry_date' => 'Contract expiry',
                                    'gross_monthly_salary' => 'Gross monthly salary (KES)',
                                    'net_monthly_salary' => 'Net monthly salary (KES)',
                                    'disbursement_method' => 'Disbursement method',
                                    'disbursement_bank' => 'Bank / SACCO',
                                    'disbursement_branch' => 'Branch',
                                    'disbursement_account_name' => 'Account name',
                                    'disbursement_account_number' => 'Account / mobile no.',
                                ] as $key => $label)
                                    @if (filled($advanceApp[$key] ?? null))
                                        <div>
                                            <dt class="text-slate-500">{{ $label }}</dt>
                                            <dd class="font-medium text-slate-800">{{ is_numeric($advanceApp[$key]) && str_contains($key, 'salary') ? number_format((float) $advanceApp[$key], 2) : ucfirst(str_replace('_', ' ', (string) $advanceApp[$key])) }}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                        </div>
                    @endif
                    @if ($loan->terms_snapshot)
                        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                            <p class="font-semibold text-slate-700">Terms accepted at application</p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($loan->terms_snapshot as $key => $value)
                                    @if ($key !== 'advance_application' && is_scalar($value))
                                        <li><span class="text-slate-500">{{ ucfirst(str_replace('_', ' ', $key)) }}:</span> {{ $value }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-card>
            </div>
        </div>
    </div>
@endsection

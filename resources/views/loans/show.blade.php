@extends('layouts.app')

@section('title', 'Loan '.$loan->loan_no)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $isActive = $loan->isActive();
        $nextDue = $loan->nextDueSchedule();
        $overdue = $loan->overdueAmount();
        $approvedSteps = $loan->approvals->whereIn('action', ['approved', 'conditional'])->count();
        $awaitingDecision = in_array($loan->status, [\App\Models\Loan::STATUS_UNDER_REVIEW, \App\Models\Loan::STATUS_GUARANTORS_PENDING], true);
        $statusTone = $loan->isClosed() ? 'neutral' : ($overdue > 0 ? 'danger' : 'brand');
    @endphp

    <x-page-header :title="'Loan '.$loan->loan_no" :subtitle="($loan->member?->full_name ?? 'Unknown member').' · '.($loan->product?->name ?? 'No product').' · '.$loan->statusLabel()">
        <a href="{{ route('loans.schedule', $loan) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="download" class="h-4 w-4" /> Schedule (CSV)
        </a>
        @if ($loan->member)
            <a href="{{ route('members.show', $loan->member) }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
                <x-icon name="users" class="h-4 w-4" /> Member profile
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Principal" :value="\App\Support\Money::format($loan->principal ?: $loan->requested_amount)" :hint="($loan->product?->methodLabel() ?? 'Interest method').' · '.($loan->interest_rate_bps / 100).'%'" />
        <x-stat label="Outstanding" :value="\App\Support\Money::format($payoff)" :hint="$overdue > 0 ? \App\Support\Money::format($overdue).' in arrears' : 'No arrears'" :tone="$statusTone" />
        <x-stat label="Repaid" :value="\App\Support\Money::format($loan->amount_paid)" :hint="$loan->progressPercent().'% of total repayable'" tone="info" />
        <x-stat label="Next due" :value="$nextDue?->due_date?->format('d M Y') ?? '—'" :hint="$nextDue ? \App\Support\Money::format($nextDue->outstanding()).' outstanding' : 'Nothing scheduled'" :tone="$nextDue && $nextDue->isOverdue() ? 'warning' : 'neutral'" />
    </div>

    <x-card title="Repayment progress">
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <div>
                <p class="font-medium text-slate-800">@money($loan->amount_paid) paid of @money($loan->total_repayable)</p>
                <p class="mt-1 text-xs text-slate-500">{{ $loan->installment_count }} installment(s) · {{ $loan->repayment_frequency }} · first due {{ $loan->first_due_date?->format('d M Y') ?? '—' }} · matures {{ $loan->maturity_date?->format('d M Y') ?? '—' }}</p>
            </div>
            <x-badge :status="$loan->status" />
        </div>
        <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-slate-200">
            <div class="h-full rounded-full {{ $overdue > 0 ? 'bg-rose-500' : 'bg-brand-500' }}" style="width: {{ $loan->progressPercent() }}%"></div>
        </div>
        <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
            <span>{{ $loan->progressPercent() }}% complete</span>
            <span>@money($payoff) outstanding</span>
        </div>
    </x-card>

    @if ($awaitingDecision && $canApprove)
        <x-card title="Approval decision" subtitle="Step {{ min($approvedSteps + 1, count($steps)) }} of {{ count($steps) }}: {{ $currentStep['name'] ?? $currentStep['role'] ?? 'Review' }}">
            <form method="POST" action="{{ route('loans.approve', $loan) }}" class="grid gap-3 sm:grid-cols-3">
                @csrf
                <x-select name="action" label="Decision" :options="['approved' => 'Approve', 'conditional' => 'Approve conditionally', 'info_requested' => 'Request more information', 'rejected' => 'Reject']" required />
                <div class="sm:col-span-2">
                    <x-input name="comments" label="Comments" placeholder="Visible to the borrower in notifications" />
                </div>
                <div class="sm:col-span-3">
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Record decision</button>
                </div>
            </form>
        </x-card>
    @endif

    @can('disburse', $loan)
        @if ($loan->status === \App\Models\Loan::STATUS_APPROVED)
            <x-card title="Disburse loan" subtitle="Disbursement posts the principal to the ledger and activates repayment.">
                <form method="POST" action="{{ route('loans.disburse', $loan) }}" class="grid gap-3 sm:grid-cols-3">
                    @csrf
                    <x-select name="method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank transfer']" required />
                    <x-input name="reference" label="Reference" placeholder="Bank slip / voucher" />
                    <div class="flex items-end">
                        <button type="submit" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Disburse @money($loan->approved_amount)</button>
                    </div>
                </form>
            </x-card>
        @endif
    @endcan

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            @if ($isActive)
                <x-card title="Record repayment" subtitle="Payments allocate automatically using the product policy.">
                    <form method="POST" action="{{ route('loans.repay', $loan) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @csrf
                        <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                        <x-select name="method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank', 'mpesa' => 'M-Pesa', 'wallet' => 'Wallet']" required />
                        <x-input name="paid_at" type="date" label="Paid on" :value="now()" />
                        <x-input name="reference" label="Reference" placeholder="Auto-generated" />
                        <div class="sm:col-span-2">
                            <x-input name="notes" label="Notes" placeholder="Optional" />
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3 flex flex-wrap items-center gap-3">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                                <x-icon name="check" class="h-4 w-4" /> Post repayment
                            </button>
                            <button type="submit" form="penalty-form" class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50">
                                <x-icon name="clock" class="h-4 w-4" /> Apply penalties
                            </button>
                        </div>
                    </form>
                </x-card>
            @endif

            <x-card title="Repayment schedule" :padding="false">
                @if ($loan->schedules->isEmpty())
                    <x-empty title="No schedule yet" message="A schedule is generated once the loan is approved and disbursed." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">#</th>
                                    <th class="px-4 py-2.5">Due</th>
                                    <th class="px-4 py-2.5 text-right">Principal</th>
                                    <th class="px-4 py-2.5 text-right">Interest</th>
                                    <th class="px-4 py-2.5 text-right">Fees</th>
                                    <th class="px-4 py-2.5 text-right">Penalty</th>
                                    <th class="px-4 py-2.5 text-right">Paid</th>
                                    <th class="px-4 py-2.5 text-right">Outstanding</th>
                                    <th class="px-4 py-2.5">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($loan->schedules as $schedule)
                                    <tr class="{{ $schedule->isOverdue() ? 'bg-rose-50/40' : 'hover:bg-slate-50' }}">
                                        <td class="px-4 py-2.5 text-slate-500">{{ $schedule->installment_no }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">
                                            {{ $schedule->due_date?->format('d M Y') }}
                                            @if ($schedule->isOverdue())
                                                <span class="ml-1 text-xs font-semibold text-rose-600">overdue</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($schedule->principal_due)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($schedule->interest_due)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($schedule->fee_due)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($schedule->penaltyDueExtra())</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($schedule->totalPaid())</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">@money($schedule->outstanding())</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$schedule->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card title="Payments" :padding="false">
                @if ($loan->payments->isEmpty())
                    <x-empty title="No repayments" message="Recorded repayments appear here with their allocation." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Paid</th>
                                    <th class="px-4 py-2.5">Method</th>
                                    <th class="px-4 py-2.5">Reference</th>
                                    <th class="px-4 py-2.5 text-right">Amount</th>
                                    <th class="px-4 py-2.5">Allocation</th>
                                    <th class="px-4 py-2.5">Received by</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($loan->payments as $payment)
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $payment->paid_at?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$payment->method === 'mpesa' ? 'processing' : 'paid'" :label="ucfirst($payment->method)" /></td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $payment->reference ?: '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-emerald-600">+@money($payment->amount)</td>
                                        <td class="px-4 py-2.5">
                                            <div class="flex flex-wrap gap-1 text-xs">
                                                @foreach (($payment->allocation ?? []) as $bucket => $value)
                                                    @if ((int) $value > 0)
                                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600">{{ ucfirst($bucket) }} @money((int) $value)</span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $payment->receiver?->name ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            @if ($loan->penaltyCharges->isNotEmpty())
                <x-card title="Penalty charges" :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Period</th>
                                    <th class="px-4 py-2.5">Installment</th>
                                    <th class="px-4 py-2.5 text-right">Amount</th>
                                    <th class="px-4 py-2.5">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($loan->penaltyCharges as $charge)
                                    <tr>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $charge->period_start?->format('d M') }} – {{ $charge->period_end?->format('d M Y') }}</td>
                                        <td class="px-4 py-2.5 text-slate-600">#{{ $charge->installment?->installment_no ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">@money($charge->amount)</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$charge->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @endif

            @if ($loan->restructures->isNotEmpty())
                <x-card title="Restructure history" :padding="false">
                    <ul class="divide-y divide-slate-100">
                        @foreach ($loan->restructures as $restructure)
                            <li class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-800">{{ $restructure->reason }}</p>
                                    <div class="flex items-center gap-2">
                                        <x-badge :status="$restructure->status" />
                                        <span class="text-xs text-slate-500">{{ $restructure->effective_date?->format('d M Y') }}</span>
                                    </div>
                                </div>
                                @if ($restructure->old_terms || $restructure->new_terms)
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $restructure->old_terms['duration'] ?? '—' }} {{ $restructure->old_terms['duration_unit'] ?? '' }} → {{ $restructure->new_terms['duration'] ?? '—' }} {{ $restructure->new_terms['duration_unit'] ?? '' }}
                                    </p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </div>

        <div class="space-y-5">
            <x-card title="Loan terms">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$loan->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Product</dt>
                        <dd class="text-right font-medium text-slate-800">{{ $loan->product?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Method</dt>
                        <dd class="text-right font-medium text-slate-800">{{ $loan->product?->methodLabel() ?? ucfirst(str_replace('_', ' ', $loan->interest_method)) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Rate</dt>
                        <dd class="font-medium text-slate-800">@bps($loan->interest_rate_bps) {{ $loan->interest_period }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Requested</dt>
                        <dd class="font-medium text-slate-800">@money($loan->requested_amount)</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Approved</dt>
                        <dd class="font-medium text-slate-800">@money($loan->approved_amount)</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Interest</dt>
                        <dd class="font-medium text-slate-800">@money($loan->total_interest)</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Fees</dt>
                        <dd class="font-medium text-slate-800">@money($loan->total_fees)</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-2.5">
                        <dt class="font-medium text-slate-700">Total repayable</dt>
                        <dd class="font-semibold text-slate-900">@money($loan->total_repayable)</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Duration</dt>
                        <dd class="font-medium text-slate-800">{{ $loan->duration }} {{ $loan->duration_unit }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Disbursed</dt>
                        <dd class="font-medium text-slate-800">{{ $loan->disbursed_at?->format('d M Y') ?? 'Not yet' }}</dd>
                    </div>
                </dl>
            </x-card>

            @php $advanceApp = data_get($loan->terms_snapshot, 'advance_application', []); @endphp
            @if (! empty($advanceApp))
                <x-card title="Advance application details">
                    <dl class="space-y-2 text-sm">
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
                                <div class="flex items-start justify-between gap-3">
                                    <dt class="text-slate-500">{{ $label }}</dt>
                                    <dd class="text-right font-medium text-slate-800">{{ is_numeric($advanceApp[$key]) && str_contains($key, 'salary') ? number_format((float) $advanceApp[$key], 2) : ucfirst(str_replace('_', ' ', (string) $advanceApp[$key])) }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                </x-card>
            @endif

            <x-card title="Approval workflow">
                <ol class="space-y-3">
                    @foreach ($steps as $index => $step)
                        @php
                            $done = $index < $approvedSteps;
                            $active = $index === $approvedSteps && $awaitingDecision;
                        @endphp
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $done ? 'bg-emerald-100 text-emerald-700' : ($active ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-slate-500') }}">
                                @if ($done)
                                    <x-icon name="check" class="h-3.5 w-3.5" />
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium {{ $done ? 'text-slate-500' : 'text-slate-800' }}">{{ $step['name'] ?? ucfirst(str_replace('_', ' ', $step['role'])) }}</p>
                                @if (! empty($step['max_amount']))
                                    <p class="text-xs text-slate-500">Up to @money((int) $step['max_amount'])</p>
                                @endif
                                @if ($active)
                                    <p class="text-xs font-medium text-brand-700">Awaiting this step</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if ($loan->status === \App\Models\Loan::STATUS_REJECTED)
                    <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">This application was rejected.</p>
                @endif
            </x-card>

            <x-card title="Approval history" :padding="false">
                @if ($loan->approvals->isEmpty())
                    <p class="p-4 text-sm text-slate-500">No decisions recorded.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($loan->approvals as $approval)
                            <li class="px-4 py-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-800">{{ $approval->actor?->name ?? 'System' }}</p>
                                    <x-badge :status="$approval->action === 'submitted' ? 'submitted' : $approval->action" />
                                </div>
                                <p class="text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $approval->role_key)) }} · {{ $approval->acted_at?->format('d M Y H:i') ?? '—' }}</p>
                                @if ($approval->comments)
                                    <p class="mt-1 text-xs text-slate-600">{{ $approval->comments }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Guarantors" :padding="false">
                @if ($loan->guarantors->isEmpty())
                    <p class="p-4 text-sm text-slate-500">No guarantors on this loan.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($loan->guarantors as $guarantor)
                            <li class="px-4 py-3">
                                <div class="flex items-center justify-between gap-2">
                                    @if ($guarantor->guarantor_member_id)
                                        <a href="{{ route('members.show', $guarantor->guarantor_member_id) }}" class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ $guarantor->inviteeLabel() }}</a>
                                    @else
                                        <span class="text-sm font-medium text-slate-800">{{ $guarantor->inviteeLabel() }}</span>
                                    @endif
                                    <x-badge :status="$guarantor->status" />
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    Guaranteed @money($guarantor->guaranteed_amount)
                                    · capacity @money($guarantor->capacity_at_request)
                                    @if ($guarantor->required_savings_amount)
                                        · savings to fund @money($guarantor->required_savings_amount)
                                    @endif
                                </p>
                                @if ($guarantor->response_note)
                                    <p class="mt-1 text-xs text-slate-600">{{ $guarantor->response_note }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Documents" :padding="false">
                @if ($loan->documents->isEmpty())
                    <p class="p-4 text-sm text-slate-500">No documents attached.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($loan->documents as $document)
                            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <a href="{{ route('documents.show', $document) }}" class="min-w-0 truncate text-sm font-medium text-slate-800 hover:text-brand-700">{{ $document->title }}</a>
                                <x-badge :status="$document->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            @if ($can('loans.restructure') && $isActive)
                <x-card title="Restructure">
                    <form method="POST" action="{{ route('loans.restructure', $loan) }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <x-input name="duration" type="number" min="1" max="600" label="New duration" required />
                            <x-select name="duration_unit" label="Unit" :options="['days' => 'Days', 'weeks' => 'Weeks', 'months' => 'Months']" required />
                        </div>
                        <x-select name="repayment_frequency" label="Frequency" :options="['weekly' => 'Weekly', 'monthly' => 'Monthly']" required />
                        <x-input name="interest_rate" type="number" step="0.01" label="New rate (%)" placeholder="Keep current" />
                        <x-textarea name="reason" label="Reason" rows="2" required />
                        <button type="submit" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Restructure loan</button>
                    </form>
                </x-card>
            @endif

            @if ($can('loans.disburse') && $isActive)
                <x-card title="Write off">
                    <p class="text-sm text-slate-600">A write-off closes the loan and posts the outstanding balance to the loan write-off expense account.</p>
                    <form method="POST" action="{{ route('loans.write-off', $loan) }}" class="mt-3 space-y-2" onsubmit="return confirm('Write off this loan? This cannot be undone.');">
                        @csrf
                        <x-textarea name="reason" label="Reason" rows="2" required />
                        <button type="submit" class="w-full rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Write off loan</button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>

    @if ($isActive)
        <form id="penalty-form" method="POST" action="{{ route('loans.penalties', $loan) }}" class="hidden">
            @csrf
        </form>
    @endif
@endsection

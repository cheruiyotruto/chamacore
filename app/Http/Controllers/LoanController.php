<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Services\EmployerFinanceService;
use App\Services\LoanOriginationService;
use App\Services\LoanServicingService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class LoanController extends Controller
{
    public function index(Request $request): View
    {
        $this->requirePermission('loans.view');

        $loans = Loan::with(['member', 'product'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(function ($inner) use ($search) {
                    $inner->where('loan_no', 'like', $search)
                        ->orWhereHas('member', fn ($q) => $q
                            ->where('first_name', 'like', $search)
                            ->orWhere('last_name', 'like', $search)
                            ->orWhere('member_no', 'like', $search));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('product'), fn ($query) => $query->where('loan_product_id', $request->integer('product')))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('loans.index', [
            'loans' => $loans,
            'products' => LoanProduct::orderBy('name')->get(),
            'statuses' => [
                Loan::STATUS_SUBMITTED, Loan::STATUS_GUARANTORS_PENDING, Loan::STATUS_UNDER_REVIEW,
                Loan::STATUS_APPROVED, Loan::STATUS_ACTIVE, Loan::STATUS_RESTRUCTURED,
                Loan::STATUS_CLOSED, Loan::STATUS_REJECTED, Loan::STATUS_DEFAULTED, Loan::STATUS_WRITTEN_OFF,
            ],
            'portfolio' => [
                'active' => Loan::whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_DISBURSED, Loan::STATUS_RESTRUCTURED])->count(),
                'outstanding' => (int) Loan::whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_DISBURSED, Loan::STATUS_RESTRUCTURED])->get()->sum(fn (Loan $loan) => $loan->outstandingTotal()),
                'pending' => Loan::whereIn('status', [Loan::STATUS_SUBMITTED, Loan::STATUS_GUARANTORS_PENDING, Loan::STATUS_UNDER_REVIEW])->count(),
            ],
        ]);
    }

    public function create(): View
    {
        $this->requirePermission('loans.create');

        $members = Member::where('status', 'active')->orderBy('first_name')->get();
        $employerFinance = app(\App\Support\EmployerFinance::class)::for($this->group());

        return view('loans.create', [
            'products' => LoanProduct::where('is_active', true)->orderBy('name')->get(),
            'members' => $members,
            'employerFinancedMemberIds' => collect($employerFinance->memberIds())
                ->intersect($members->pluck('id'))
                ->values()
                ->all(),
        ]);
    }

    public function quote(Request $request, LoanOriginationService $origination): JsonResponse
    {
        $this->requirePermission('loans.view');

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'loan_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'duration' => ['required', 'integer', 'min:1', 'max:600'],
            'duration_unit' => ['nullable', Rule::in(['days', 'weeks', 'months'])],
        ]);

        $member = Member::where('status', 'active')->findOrFail($data['member_id']);
        $product = LoanProduct::where('is_active', true)->findOrFail($data['loan_product_id']);

        try {
            $quotation = $origination->quotation(
                $member,
                $product,
                Money::toMinor($data['amount']),
                (int) $data['duration'],
                $data['duration_unit'] ?? null,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'quote' => $quotation['quote_array'],
            'fees' => $quotation['fees'],
            'multiplier' => $quotation['multiplier'],
        ]);
    }

    public function store(Request $request, LoanOriginationService $origination): RedirectResponse
    {
        $this->requirePermission('loans.create');

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'loan_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'duration' => ['required', 'integer', 'min:1', 'max:600'],
            'duration_unit' => ['nullable', Rule::in(['days', 'weeks', 'months'])],
            'purpose' => ['nullable', 'string', 'max:120'],
            'purpose_description' => ['nullable', 'string', 'max:1000'],
            'guarantor_ids' => ['nullable', 'array'],
            'guarantor_ids.*' => ['integer'],
            'guarantor_amounts' => ['nullable', 'array'],
            'guarantor_amounts.*' => ['nullable', 'numeric', 'min:0'],
            'non_member_guarantors' => ['nullable', 'array', 'max:10'],
            'non_member_guarantors.*.email' => ['nullable', 'email', 'max:190', 'required_without:non_member_guarantors.*.phone'],
            'non_member_guarantors.*.phone' => ['nullable', 'string', 'max:30', 'required_without:non_member_guarantors.*.email'],
            'non_member_guarantors.*.amount' => ['nullable', 'numeric', 'min:0'],
            'non_member_guarantors.*.channels' => ['nullable', 'array'],
            'non_member_guarantors.*.channels.*' => ['string', Rule::in(MemberInvitation::CHANNELS)],
            'payslips' => ['nullable', 'array', 'max:5'],
            'payslips.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'employment_letter' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'signature' => ['nullable', 'string'],
            'advance_application' => ['nullable', 'array'],
            'advance_application.kra_pin' => ['nullable', 'string', 'max:30'],
            'advance_application.payroll_number' => ['nullable', 'string', 'max:50'],
            'advance_application.department' => ['nullable', 'string', 'max:120'],
            'advance_application.job_title' => ['nullable', 'string', 'max:120'],
            'advance_application.employment_terms' => ['nullable', 'string', Rule::in(['permanent', 'contract', 'casual', 'other'])],
            'advance_application.contract_expiry_date' => ['nullable', 'date'],
            'advance_application.gross_monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'advance_application.net_monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'advance_application.disbursement_method' => ['nullable', 'string', Rule::in(['bank', 'fosa', 'mobile_money', 'cash', 'other'])],
            'advance_application.disbursement_bank' => ['nullable', 'string', 'max:120'],
            'advance_application.disbursement_branch' => ['nullable', 'string', 'max:120'],
            'advance_application.disbursement_account_name' => ['nullable', 'string', 'max:150'],
            'advance_application.disbursement_account_number' => ['nullable', 'string', 'max:80'],
        ]);

        $member = Member::where('status', 'active')->findOrFail($data['member_id']);
        $product = LoanProduct::where('is_active', true)->findOrFail($data['loan_product_id']);

        $requiresEmployerFinance = app(\App\Support\EmployerFinance::class)::for($this->group())->appliesTo($member)
            || $product->requiresEmployerRemittance();

        if ($requiresEmployerFinance) {
            $request->validate([
                'payslips' => ['required', 'array', 'min:1', 'max:5'],
                'payslips.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
                'employment_letter' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
                'signature' => ['required', 'string'],
            ]);
        }

        $guarantors = collect($data['guarantor_ids'] ?? [])
            ->filter()
            ->unique()
            ->map(fn ($id) => [
                'member_id' => (int) $id,
                'amount' => isset($data['guarantor_amounts'][$id])
                    ? Money::toMinor($data['guarantor_amounts'][$id])
                    : null,
            ])
            ->values()
            ->all();

        $nonMemberGuarantors = collect($data['non_member_guarantors'] ?? [])
            ->filter(fn ($row) => filled($row['email'] ?? null) || filled($row['phone'] ?? null))
            ->unique(fn ($row) => ($row['email'] ?? '')
                ? 'email:'.strtolower(trim($row['email']))
                : 'phone:'.trim($row['phone'] ?? ''))
            ->map(fn ($row) => [
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'channels' => $row['channels'] ?? null,
                'amount' => filled($row['amount'] ?? null) ? Money::toMinor($row['amount']) : null,
            ])
            ->values()
            ->all();

        $guarantors = array_merge($guarantors, $nonMemberGuarantors);

        try {
            $loan = $origination->apply($member, $product, [
                'amount' => Money::toMinor($data['amount']),
                'duration' => (int) $data['duration'],
                'duration_unit' => $data['duration_unit'] ?? $product->duration_unit,
                'purpose' => $data['purpose'] ?? null,
                'purpose_description' => $data['purpose_description'] ?? null,
                'guarantors' => $guarantors,
                'advance_application' => $data['advance_application'] ?? null,
            ], $this->actor());

            if ($requiresEmployerFinance) {
                app(\App\Services\AdvanceLoanDocumentService::class)->storeApplicationDocuments(
                    $loan,
                    $member,
                    $this->actor(),
                    $request->file('payslips', []),
                    $request->file('employment_letter'),
                    $data['signature'] ?? null,
                );
            }
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('loans.show', $loan)->with('status', 'Loan application '.$loan->loan_no.' submitted.');
    }

    public function show(Loan $loan, LoanServicingService $servicing): View
    {
        $this->authorize('view', $loan);

        $loan->load([
            'member', 'product', 'schedules', 'payments.receiver', 'approvals.actor',
            'guarantors.guarantor', 'penaltyCharges', 'restructures', 'documents',
        ]);

        return view('loans.show', [
            'loan' => $loan,
            'steps' => app(LoanOriginationService::class)->approvalSteps($loan),
            'currentStep' => app(LoanOriginationService::class)->currentStep($loan),
            'payoff' => $loan->outstandingTotal(),
            'canApprove' => $this->context()->hasPermission('loans.approve'),
        ]);
    }

    public function approve(Request $request, Loan $loan, LoanOriginationService $origination): RedirectResponse
    {
        $this->authorize('approve', $loan);

        $data = $request->validate([
            'action' => ['required', Rule::in(['approved', 'rejected', 'info_requested', 'conditional'])],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $origination->approve($loan, $this->actor(), $data['action'], $data['comments'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Loan decision recorded.');
    }

    public function confirmEmployerFinance(Request $request, Loan $loan, EmployerFinanceService $service): RedirectResponse
    {
        $this->requirePermission('loans.approve');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'decline'])],
            'officer_name' => ['required', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->confirm(
                $loan,
                $data['decision'] === 'approve',
                $data['officer_name'],
                $data['reference'] ?? null,
                $data['notes'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Employer finance decision recorded.');
    }

    public function disburse(Request $request, Loan $loan, LoanOriginationService $origination): RedirectResponse
    {
        $this->authorize('disburse', $loan);

        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'bank'])],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $origination->disburse($loan, $this->actor(), $data['method'], $data['reference'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Loan disbursed and posted to the ledger.');
    }

    public function repay(Request $request, Loan $loan, LoanServicingService $servicing): RedirectResponse
    {
        $this->requirePermission('loans.view');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'bank', 'mpesa', 'wallet'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $reference = $data['reference'] ?: 'REP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));

        try {
            $servicing->recordPayment(
                loan: $loan,
                amount: Money::toMinor($data['amount']),
                method: $data['method'],
                reference: $reference,
                idempotencyKey: 'loan-repayment-'.$reference,
                receivedBy: $this->actor(),
                paidAt: $data['paid_at'] ?? null,
                notes: $data['notes'] ?? null,
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Repayment recorded and allocated.');
    }

    public function penalties(Loan $loan, LoanServicingService $servicing): RedirectResponse
    {
        $this->requirePermission('loans.view');

        $count = $servicing->applyPenalties($loan);

        return back()->with('status', $count.' penalty charge(s) applied.');
    }

    public function restructure(Request $request, Loan $loan, LoanServicingService $servicing): RedirectResponse
    {
        $this->authorize('restructure', $loan);

        $data = $request->validate([
            'duration' => ['required', 'integer', 'min:1', 'max:600'],
            'duration_unit' => ['required', Rule::in(['days', 'weeks', 'months'])],
            'repayment_frequency' => ['required', Rule::in(['weekly', 'monthly'])],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $servicing->restructure($loan, [
                'duration' => (int) $data['duration'],
                'duration_unit' => $data['duration_unit'],
                'repayment_frequency' => $data['repayment_frequency'],
                'interest_rate_bps' => isset($data['interest_rate']) ? (int) round($data['interest_rate'] * 100) : null,
                'reason' => $data['reason'],
            ], $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Loan restructured with a new schedule.');
    }

    public function writeOff(Request $request, Loan $loan, LoanServicingService $servicing): RedirectResponse
    {
        $this->requirePermission('loans.disburse');

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $servicing->writeOff($loan, $this->actor(), $data['reason']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Loan written off and posted to the ledger.');
    }

    public function schedule(Loan $loan)
    {
        $this->authorize('view', $loan);

        return $this->csv(
            'loan-schedule-'.$loan->loan_no.'.csv',
            ['#', 'Due date', 'Principal', 'Interest', 'Fees', 'Penalty', 'Total', 'Paid', 'Status'],
            $loan->schedules->map(fn ($schedule) => [
                $schedule->installment_no,
                $schedule->due_date->toDateString(),
                number_format($schedule->principal_due / 100, 2, '.', ''),
                number_format($schedule->interest_due / 100, 2, '.', ''),
                number_format($schedule->fee_due / 100, 2, '.', ''),
                number_format($schedule->penalty_due / 100, 2, '.', ''),
                number_format($schedule->total_due / 100, 2, '.', ''),
                number_format($schedule->totalPaid() / 100, 2, '.', ''),
                $schedule->status,
            ])
        );
    }
}

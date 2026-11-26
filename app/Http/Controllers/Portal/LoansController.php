<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\MemberInvitation;
use App\Services\AdvanceLoanDocumentService;
use App\Services\EmployerFinanceService;
use App\Services\LoanOriginationService;
use App\Services\LoanServicingService;
use App\Support\GuaranteeSettings;
use App\Support\SignatureCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoansController extends Controller
{
    public function __construct(
        private readonly LoanOriginationService $origination,
        private readonly EmployerFinanceService $employerFinance,
        private readonly AdvanceLoanDocumentService $advanceDocuments,
    ) {
    }

    public function index(): View
    {
        $this->authorize('viewAny', Loan::class);

        $loans = Loan::query()
            ->with('product')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('portal.loans.index', [
            'loans' => $loans,
            'guaranteeRequests' => Guarantor::query()
                ->with([
                    'loan' => fn ($loan) => $loan->withoutGlobalScopes()->with([
                        'member' => fn ($member) => $member->withoutGlobalScopes(),
                    ]),
                ])
                ->where('guarantor_member_id', $this->membership()->id)
                ->where('status', Guarantor::STATUS_PENDING)
                ->get(),
            'acceptedGuarantees' => Guarantor::query()
                ->with([
                    'loan' => fn ($loan) => $loan->withoutGlobalScopes()->with([
                        'member' => fn ($member) => $member->withoutGlobalScopes(),
                    ]),
                ])
                ->where('guarantor_member_id', $this->membership()->id)
                ->where('status', Guarantor::STATUS_ACCEPTED)
                ->doesntHave('signatures')
                ->get(),
            'awaitingSavingsGuarantees' => Guarantor::query()
                ->with([
                    'loan' => fn ($loan) => $loan->withoutGlobalScopes()->with([
                        'member' => fn ($member) => $member->withoutGlobalScopes(),
                    ]),
                ])
                ->where('guarantor_member_id', $this->membership()->id)
                ->where('status', Guarantor::STATUS_AWAITING_SAVINGS)
                ->get(),
            'requireGuaranteeSignature' => GuaranteeSettings::for($this->group())->requiresSignature(),
        ]);
    }

    public function show(Loan $loan, LoanServicingService $servicing): View
    {
        $this->authorize('view', $loan);

        $loan->load(['product', 'schedules', 'payments', 'guarantors.guarantor', 'approvals', 'documents']);

        return view('portal.loans.show', [
            'loan' => $loan,
            'payoff' => $loan->isActive() ? $servicing->payoffAmount($loan) : 0,
            'advanceRequirements' => $loan->requiresEmployerFinance()
                ? $this->advanceDocuments->requirements($loan)
                : null,
        ]);
    }

    public function create(): View
    {
        $this->authorize('apply', Loan::class);

        return view('portal.loans.create', [
            'requiresEmployerFinance' => $this->employerFinance->appliesTo($this->membership()),
            'products' => LoanProduct::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'employerFinanceSettings' => $this->employerFinance->settingsFor($this->membership()),
            'members' => \App\Models\Member::query()
                ->active()
                ->where('id', '!=', $this->membership()->id)
                ->orderBy('first_name')
                ->get(),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $this->authorize('apply', Loan::class);

        $data = $request->validate([
            'loan_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
            'duration' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['nullable', 'in:days,weeks,months,years'],
        ]);

        $product = LoanProduct::query()->where('is_active', true)->findOrFail($data['loan_product_id']);

        try {
            $quotation = $this->origination->quotation(
                $this->membership(),
                $product,
                (int) round(((float) $data['amount']) * 100),
                (int) $data['duration'],
                $data['duration_unit'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'quotation' => $quotation['quote_array'],
            'fees' => $quotation['fees'],
            'multiplier' => $quotation['multiplier'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('apply', Loan::class);

        $data = $request->validate([
            'loan_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
            'duration' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['nullable', 'in:days,weeks,months,years'],
            'purpose' => ['nullable', 'string', 'max:100'],
            'purpose_description' => ['nullable', 'string', 'max:2000'],
            'guarantors' => ['nullable', 'array'],
            'guarantors.*' => ['integer'],
            'non_member_guarantors' => ['nullable', 'array', 'max:10'],
            'non_member_guarantors.*.email' => ['nullable', 'email', 'max:190', 'required_without:non_member_guarantors.*.phone'],
            'non_member_guarantors.*.phone' => ['nullable', 'string', 'max:30', 'required_without:non_member_guarantors.*.email'],
            'non_member_guarantors.*.channels' => ['nullable', 'array'],
            'non_member_guarantors.*.channels.*' => ['string', Rule::in(MemberInvitation::CHANNELS)],
            'payslips' => ['nullable', 'array', 'max:5'],
            'payslips.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'employment_letter' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'signature' => SignatureCapture::rules(),
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

        $product = LoanProduct::query()->where('is_active', true)->findOrFail($data['loan_product_id']);
        $requiresEmployerFinance = $this->employerFinance->appliesTo($this->membership())
            || $product->requiresEmployerRemittance();

        if ($requiresEmployerFinance) {
            $missing = [];

            if (! $request->hasFile('payslips') || count($request->file('payslips')) === 0) {
                $missing['payslips'] = 'Attach at least one payslip for this advance loan.';
            }

            if (! $request->hasFile('employment_letter')) {
                $missing['employment_letter'] = 'Attach your employment letter for this advance loan.';
            }

            if (! $request->filled('signature')) {
                $missing['signature'] = 'Draw your online signature to authorise this advance loan.';
            }

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
        }

        $guarantors = collect($data['guarantors'] ?? [])
            ->filter()
            ->unique()
            ->map(fn ($id) => ['member_id' => (int) $id])
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
            ])
            ->values()
            ->all();

        $guarantors = array_merge($guarantors, $nonMemberGuarantors);

        try {
            $loan = $this->origination->apply($this->membership(), $product, [
                'amount' => (int) round(((float) $data['amount']) * 100),
                'duration' => (int) $data['duration'],
                'duration_unit' => $data['duration_unit'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'purpose_description' => $data['purpose_description'] ?? null,
                'guarantors' => $guarantors,
                'advance_application' => $data['advance_application'] ?? null,
            ], $this->actor());

            if ($requiresEmployerFinance) {
                $this->advanceDocuments->storeApplicationDocuments(
                    $loan,
                    $this->membership(),
                    $this->actor(),
                    $request->file('payslips') ?? [],
                    $request->file('employment_letter'),
                    $data['signature'] ?? null
                );
            }
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.loans.show', $loan)
            ->with('status', 'Loan application '.$loan->loan_no.' submitted.');
    }

    public function respondToGuarantee(Request $request, Guarantor $guarantor): RedirectResponse
    {
        abort_unless($guarantor->guarantor_member_id === $this->membership()->id, 403);

        $data = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $accept = $data['decision'] === 'accept';

        try {
            $this->origination->respondToGuarantee(
                $guarantor,
                $this->membership(),
                $accept,
                $data['note'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($accept && GuaranteeSettings::for($this->group())->requiresSignature()) {
            return redirect()->route('portal.guarantees.sign', $guarantor)
                ->with('status', 'Guarantee accepted. Add your electronic signature to complete it.');
        }

        return back()->with('status', 'Guarantee response recorded.');
    }

    public function signForm(Guarantor $guarantor): View
    {
        abort_unless($guarantor->guarantor_member_id === $this->membership()->id, 403);
        abort_unless($guarantor->status === Guarantor::STATUS_ACCEPTED, 404);

        $guarantor->load([
            'loan' => fn ($loan) => $loan->withoutGlobalScopes()->with([
                'member' => fn ($member) => $member->withoutGlobalScopes(),
            ]),
        ]);

        return view('portal.loans.sign-guarantee', [
            'guarantee' => $guarantor,
            'alreadySigned' => $guarantor->signatures()->exists(),
        ]);
    }

    public function signGuarantee(Request $request, Guarantor $guarantor): RedirectResponse
    {
        abort_unless($guarantor->guarantor_member_id === $this->membership()->id, 403);

        $data = $request->validate([
            'signature' => ['required', ...SignatureCapture::RULES],
        ]);

        try {
            $this->origination->signGuarantee($guarantor, $this->membership(), $data['signature']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.loans.index')
            ->with('status', 'Your guarantee signature has been stored.');
    }

    public function schedule(Loan $loan)
    {
        $this->authorize('view', $loan);

        $loan->load('schedules');

        return $this->csv('loan-schedule-'.$loan->loan_no.'.csv', [
            'No', 'Due date', 'Principal', 'Interest', 'Fees', 'Penalty', 'Total due', 'Status',
        ], $loan->schedules->map(fn ($schedule) => [
            $schedule->installment_no,
            $schedule->due_date?->toDateString(),
            number_format($schedule->principal_due / 100, 2, '.', ''),
            number_format($schedule->interest_due / 100, 2, '.', ''),
            number_format($schedule->fee_due / 100, 2, '.', ''),
            number_format($schedule->penaltyDueExtra() / 100, 2, '.', ''),
            number_format($schedule->totalDue() / 100, 2, '.', ''),
            $schedule->status,
        ]));
    }
}

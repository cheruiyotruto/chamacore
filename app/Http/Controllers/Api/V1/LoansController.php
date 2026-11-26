<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\MemberInvitation;
use App\Services\LoanOriginationService;
use App\Services\LoanServicingService;
use App\Services\PaymentService;
use App\Support\GuaranteeSettings;
use App\Support\SignatureCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LoansController extends Controller
{
    public function __construct(
        private readonly LoanOriginationService $origination,
        private readonly LoanServicingService $servicing,
    ) {
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Loan::class);

        $loans = Loan::query()->with('product')->orderByDesc('created_at')->paginate(15);

        return response()->json([
            'data' => $loans->through(fn (Loan $loan) => $this->loanPayload($loan)),
            'guarantee_requests' => Guarantor::query()
                ->with(['loan:id,loan_no,requested_amount'])
                ->where('guarantor_member_id', $this->membership()->id)
                ->where('status', Guarantor::STATUS_PENDING)
                ->get()
                ->map(fn (Guarantor $guarantor) => $this->guarantorPayload($guarantor))
                ->values(),
        ]);
    }

    public function products(): JsonResponse
    {
        $this->authorize('apply', Loan::class);

        return response()->json([
            'data' => LoanProduct::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (LoanProduct $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'code' => $product->code,
                    'category' => $product->category,
                    'category_label' => $product->categoryLabel(),
                    'description' => $product->description,
                    'interest_method' => $product->interest_method,
                    'interest_rate_bps' => $product->interest_rate_bps,
                    'interest_period' => $product->interest_period,
                    'repayment_frequency' => $product->repayment_frequency,
                    'duration_min' => $product->duration_min,
                    'duration_max' => $product->duration_max,
                    'duration_unit' => $product->duration_unit,
                    'min_amount' => $product->min_amount,
                    'max_amount' => $product->max_amount,
                    'guarantor_mode' => $product->guarantorMode(),
                    'requires_guarantors' => $product->guarantorsRequired(),
                    'min_guarantors' => $product->min_guarantors,
                ])->values(),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $this->authorize('apply', Loan::class);

        $data = $this->validatedApplication($request);
        $product = LoanProduct::query()->where('is_active', true)->findOrFail($data['loan_product_id']);

        try {
            $quotation = $this->origination->quotation(
                $this->membership(),
                $product,
                $this->cents($data['amount']),
                (int) $data['duration'],
                $data['duration_unit'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'product' => ['id' => $product->id, 'name' => $product->name],
            'quotation' => $quotation['quote_array'],
            'fees' => $quotation['fees'],
            'multiplier' => $quotation['multiplier'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('apply', Loan::class);

        $data = $this->validatedApplication($request);
        $product = LoanProduct::query()->where('is_active', true)->findOrFail($data['loan_product_id']);

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
                'amount' => $this->cents($data['amount']),
                'duration' => (int) $data['duration'],
                'duration_unit' => $data['duration_unit'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'purpose_description' => $data['purpose_description'] ?? null,
                'guarantors' => $guarantors,
            ], $this->actor());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Loan application submitted.',
            'loan' => $this->loanPayload($loan->load('product')),
        ], 201);
    }

    public function show(Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);

        $loan->load(['product', 'schedules', 'payments', 'guarantors.guarantor', 'approvals']);

        return response()->json([
            'loan' => $this->loanPayload($loan),
            'payoff_amount' => $loan->isActive() ? $this->servicing->payoffAmount($loan) : 0,
            'schedule' => $loan->schedules->map(fn (LoanSchedule $schedule) => $this->schedulePayload($schedule)),
            'payments' => $loan->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ])->values(),
            'guarantors' => $loan->guarantors->map(fn (Guarantor $guarantor) => $this->guarantorPayload($guarantor))->values(),
            'approvals' => $loan->approvals->map(fn ($approval) => [
                'step_index' => $approval->step_index,
                'role_key' => $approval->role_key,
                'action' => $approval->action,
                'comments' => $approval->comments,
                'acted_at' => $approval->acted_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function schedule(Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);

        return response()->json([
            'data' => $loan->schedules()->get()
                ->map(fn (LoanSchedule $schedule) => $this->schedulePayload($schedule))
                ->values(),
        ]);
    }

    public function guarantees(): JsonResponse
    {
        $guarantees = Guarantor::query()
            ->with(['loan.member', 'loan.product'])
            ->where('guarantor_member_id', $this->membership()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $guarantees->through(fn (Guarantor $guarantor) => $this->guarantorPayload($guarantor)),
        ]);
    }

    public function respondToGuarantee(Request $request, Guarantor $guarantor): JsonResponse
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
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $signatureRequired = $accept
            && GuaranteeSettings::for($this->group())->requiresSignature();

        return response()->json([
            'message' => 'Guarantee response recorded.',
            'signature_required' => $signatureRequired,
            'sign_url' => $signatureRequired
                ? route('api.loans.guarantees.sign', $guarantor)
                : null,
        ]);
    }

    public function signGuarantee(Request $request, Guarantor $guarantor): JsonResponse
    {
        abort_unless($guarantor->guarantor_member_id === $this->membership()->id, 403);

        $data = $request->validate([
            'signature' => ['required', ...SignatureCapture::RULES],
        ]);

        try {
            $document = $this->origination->signGuarantee(
                $guarantor,
                $this->membership(),
                $data['signature']
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Guarantee signature stored.',
            'document_id' => $document->id,
        ]);
    }

    public function repay(Request $request, Loan $loan, PaymentService $payments): JsonResponse
    {
        $this->authorize('view', $loan);

        if (! $loan->isActive()) {
            return response()->json(['message' => 'This loan is not open for repayment.'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        try {
            $intent = $payments->initiate($this->membership(), [
                'purpose' => 'loan_repayment',
                'amount' => $this->cents($data['amount']),
                'phone' => $data['phone'],
                'payload' => ['loan_id' => $loan->id],
            ], 'loan-repay-'.Str::uuid());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Approve the M-Pesa request on your phone to complete the repayment.',
            'payment' => [
                'uuid' => $intent->uuid,
                'status' => $intent->status,
                'amount' => $intent->amount,
                'provider_reference' => $intent->provider_reference,
            ],
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedApplication(Request $request): array
    {
        return $request->validate([
            'loan_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
            'duration' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['nullable', 'in:days,weeks,months'],
            'purpose' => ['nullable', 'string', 'max:100'],
            'purpose_description' => ['nullable', 'string', 'max:2000'],
            'guarantors' => ['nullable', 'array'],
            'guarantors.*' => ['integer'],
            'non_member_guarantors' => ['nullable', 'array', 'max:10'],
            'non_member_guarantors.*.email' => ['nullable', 'email', 'max:190', 'required_without:non_member_guarantors.*.phone'],
            'non_member_guarantors.*.phone' => ['nullable', 'string', 'max:30', 'required_without:non_member_guarantors.*.email'],
            'non_member_guarantors.*.channels' => ['nullable', 'array'],
            'non_member_guarantors.*.channels.*' => ['string', Rule::in(MemberInvitation::CHANNELS)],
        ]);
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * @return array<string, mixed>
     */
    private function loanPayload(Loan $loan): array
    {
        return [
            'id' => $loan->id,
            'loan_no' => $loan->loan_no,
            'status' => $loan->status,
            'product' => $loan->product?->name,
            'requested_amount' => $loan->requested_amount,
            'approved_amount' => $loan->approved_amount,
            'principal' => $loan->principal,
            'total_repayable' => $loan->total_repayable,
            'outstanding_principal' => $loan->outstanding_principal,
            'outstanding_interest' => $loan->outstanding_interest,
            'outstanding_fees' => $loan->outstanding_fees,
            'outstanding_penalties' => $loan->outstanding_penalties,
            'outstanding_total' => $loan->outstandingTotal(),
            'amount_paid' => $loan->amount_paid,
            'progress_percent' => $loan->progressPercent(),
            'duration' => $loan->duration,
            'duration_unit' => $loan->duration_unit,
            'repayment_frequency' => $loan->repayment_frequency,
            'first_due_date' => $loan->first_due_date?->toDateString(),
            'maturity_date' => $loan->maturity_date?->toDateString(),
            'created_at' => $loan->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(LoanSchedule $schedule): array
    {
        return [
            'installment_no' => $schedule->installment_no,
            'due_date' => $schedule->due_date?->toDateString(),
            'principal_due' => $schedule->principal_due,
            'interest_due' => $schedule->interest_due,
            'fee_due' => $schedule->fee_due,
            'penalty_due' => $schedule->penaltyDueExtra(),
            'total_due' => $schedule->totalDue(),
            'total_paid' => $schedule->totalPaid(),
            'outstanding' => $schedule->outstanding(),
            'status' => $schedule->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guarantorPayload(Guarantor $guarantor): array
    {
        return [
            'id' => $guarantor->id,
            'loan_id' => $guarantor->loan_id,
            'loan_no' => $guarantor->loan?->loan_no,
            'borrower' => $guarantor->loan?->member?->full_name,
            'amount' => $guarantor->guaranteed_amount,
            'status' => $guarantor->status,
            'invitee_email' => $guarantor->invitee_email,
            'invitee_phone' => $guarantor->invitee_phone,
            'required_savings_amount' => $guarantor->required_savings_amount,
            'invitation_url' => $guarantor->invitation?->joinUrl(),
            'requested_at' => $guarantor->requested_at?->toIso8601String(),
            'funded_at' => $guarantor->funded_at?->toIso8601String(),
            'responded_at' => $guarantor->responded_at?->toIso8601String(),
            'response_note' => $guarantor->response_note,
            'portal_url' => route('portal.loans.index', absolute: false).'#guarantee-'.$guarantor->id,
            'signature_url' => $guarantor->status === Guarantor::STATUS_ACCEPTED
                && $guarantor->signatures()->doesntExist()
                    ? route('api.loans.guarantees.sign', $guarantor)
                    : null,
        ];
    }
}

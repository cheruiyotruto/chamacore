<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundCycle;
use App\Models\OnBehalfPayment as OnBehalfPaymentRecord;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Services\AuditService;
use App\Services\BillService;
use App\Services\LedgerService;
use App\Services\LoanServicingService;
use App\Services\MerryGoRoundService;
use App\Services\PaymentService;
use App\Services\SavingsService;
use App\Services\WalletService;
use App\Support\Money;
use App\Support\OnBehalfPayment as OnBehalfPayments;
use App\Support\PortalAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OnBehalfPaymentController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly SavingsService $savings,
        private readonly LoanServicingService $loans,
        private readonly BillService $bills,
        private readonly MerryGoRoundService $mgr,
        private readonly PaymentService $payments,
    ) {}

    public function index(): View
    {
        [$group, $payer] = $this->ensureEnabled();

        $members = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->where('id', '!=', $payer->id)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $memberOptions = $members->mapWithKeys(
            fn (Member $member) => [$member->id => $member->member_no.' · '.$member->full_name]
        )->all();

        $memberIds = $members->pluck('id')->all();
        $memberNames = $members->mapWithKeys(fn (Member $member) => [$member->id => $member->full_name]);

        $savingsAccounts = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->whereIn('member_id', $memberIds)
            ->orderBy('account_no')
            ->get()
            ->mapWithKeys(function (SavingsAccount $account) use ($memberNames) {
                $owner = $memberNames->get($account->member_id, 'Member #'.$account->member_id);

                return [$account->id => $owner.' · '.$account->account_no.' · '.Money::format($account->balance)];
            })->all();

        $loans = Loan::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->whereIn('status', [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESTRUCTURED])
            ->whereIn('member_id', $memberIds)
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(function (Loan $loan) use ($memberNames) {
                $owner = $memberNames->get($loan->member_id, 'Member #'.$loan->member_id);

                return [$loan->id => $owner.' · '.$loan->loan_no.' · '.Money::format($loan->outstandingTotal())];
            })->all();

        $bills = Bill::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->whereIn('member_id', $memberIds)
            ->whereIn('status', [Bill::STATUS_PENDING, Bill::STATUS_PARTIALLY_PAID, Bill::STATUS_OVERDUE])
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(function (Bill $bill) use ($memberNames) {
                $owner = $memberNames->get($bill->member_id, 'Member #'.$bill->member_id);

                return [$bill->id => $owner.' · '.$bill->bill_no.' · '.Money::format($bill->outstanding())];
            })->all();

        $activeCycleIds = MerryGoRoundCycle::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $contributions = MerryGoRoundContribution::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->whereIn('member_id', $memberIds)
            ->whereIn('merry_go_round_cycle_id', $activeCycleIds)
            ->whereIn('status', ['pending', 'late'])
            ->orderBy('due_date')
            ->get()
            ->mapWithKeys(function (MerryGoRoundContribution $contribution) use ($memberNames) {
                $owner = $memberNames->get($contribution->member_id, 'Member #'.$contribution->member_id);
                $total = $this->mgr->portalAmount($contribution)['total'];

                return [$contribution->id => $owner.' · '.($contribution->due_date?->format('d M Y') ?? 'No due date').' · '.Money::format($total)];
            })->all();

        $recent = OnBehalfPaymentRecord::withoutGlobalScopes()
            ->with([
                'member' => fn ($query) => $query->withoutGlobalScopes(),
                'payer' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('group_id', $group->id)
            ->where('payer_member_id', $payer->id)
            ->latest()
            ->limit(10)
            ->get();

        return view('portal.on-behalf.index', [
            'payer' => $payer,
            'walletBalance' => $this->wallets->ensureWallet($payer)->available_balance,
            'memberOptions' => $memberOptions,
            'savingsAccounts' => $savingsAccounts,
            'loans' => $loans,
            'bills' => $bills,
            'contributions' => $contributions,
            'recent' => $recent,
            'types' => OnBehalfPayments::TYPES,
            'methodOptions' => ['wallet' => 'My wallet', 'mpesa' => 'M-Pesa'],
        ]);
    }

    public function storeSavings(Request $request): RedirectResponse
    {
        $data = $this->validated($request, [
            'savings_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        [$group, $payer] = $this->ensureEnabled();
        $beneficiary = $this->resolveBeneficiary($request, $group, $payer);
        $account = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $beneficiary->id)
            ->where('status', 'active')
            ->find((int) $data['savings_account_id']);

        if (! $account) {
            throw ValidationException::withMessages([
                'savings_account_id' => 'Select an active savings account belonging to the selected member.',
            ]);
        }

        $amount = Money::toMinor($data['amount']);
        $key = 'ob-savings-'.$account->id.'-'.Str::uuid();

        if ($data['method'] === 'mpesa') {
            return $this->requestMpesa(
                $group,
                $payer,
                $beneficiary,
                'savings',
                'savings_deposit',
                $amount,
                [
                    'savings_account_id' => $account->id,
                    'manual_charge' => $this->manualCharge($data),
                ],
                $key,
                $account
            );
        }

        $reference = $this->reference('OBSAV');

        try {
            $this->savings->deposit(
                member: $beneficiary,
                account: $account,
                amount: $amount,
                counterAccountCode: LedgerService::CASH,
                reference: $reference,
                idempotencyKey: $key,
                description: 'Savings deposit paid by '.$payer->full_name,
                source: null,
                viaWallet: true,
                payer: $payer
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->record('savings', $group, $payer, $beneficiary, $amount, 'wallet', $reference, $account);

        return back()->with('status', 'Savings deposit of '.Money::format($amount).' made for '.$beneficiary->full_name.'.');
    }

    public function storeLoan(Request $request): RedirectResponse
    {
        $data = $this->validated($request, [
            'loan_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        [$group, $payer] = $this->ensureEnabled();
        $beneficiary = $this->resolveBeneficiary($request, $group, $payer);
        $loan = Loan::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $beneficiary->id)
            ->whereIn('status', [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESTRUCTURED])
            ->find((int) $data['loan_id']);

        if (! $loan) {
            throw ValidationException::withMessages([
                'loan_id' => 'Select an active loan belonging to the selected member.',
            ]);
        }

        $amount = Money::toMinor($data['amount']);
        $key = 'ob-loan-'.$loan->id.'-'.Str::uuid();

        if ($data['method'] === 'mpesa') {
            return $this->requestMpesa(
                $group,
                $payer,
                $beneficiary,
                'loan',
                'loan_repayment',
                $amount,
                [
                    'loan_id' => $loan->id,
                    'manual_charge' => $this->manualCharge($data),
                ],
                $key,
                $loan
            );
        }

        $reference = $this->reference('OBLOAN');

        try {
            $this->loans->recordPayment(
                loan: $loan,
                amount: $amount,
                method: 'wallet',
                reference: $reference,
                idempotencyKey: $key,
                payer: $payer
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->record('loan', $group, $payer, $beneficiary, $amount, 'wallet', $reference, $loan);

        return back()->with('status', 'Loan repayment of '.Money::format($amount).' made for '.$beneficiary->full_name.'.');
    }

    public function storeBill(Request $request): RedirectResponse
    {
        $data = $this->validated($request, [
            'bill_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        [$group, $payer] = $this->ensureEnabled();
        $beneficiary = $this->resolveBeneficiary($request, $group, $payer);
        $bill = Bill::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $beneficiary->id)
            ->whereIn('status', [Bill::STATUS_PENDING, Bill::STATUS_PARTIALLY_PAID, Bill::STATUS_OVERDUE])
            ->find((int) $data['bill_id']);

        if (! $bill) {
            throw ValidationException::withMessages([
                'bill_id' => 'Select an unpaid bill belonging to the selected member.',
            ]);
        }

        $amount = Money::toMinor($data['amount']);
        $manualCharge = $this->manualCharge($data);
        $key = 'ob-bill-'.$bill->id.'-'.Str::uuid();

        if ($data['method'] === 'mpesa') {
            return $this->requestMpesa(
                $group,
                $payer,
                $beneficiary,
                'bill',
                'bill_payment',
                $amount,
                [
                    'bill_id' => $bill->id,
                    'manual_charge' => $manualCharge,
                ],
                $key,
                $bill
            );
        }

        try {
            $payment = $this->bills->payWithWallet($payer, $bill, $amount, $manualCharge, $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->record('bill', $group, $payer, $beneficiary, $amount, 'wallet', $payment->reference, $bill);

        return back()->with('status', 'Bill payment of '.Money::format($amount).' made for '.$beneficiary->full_name.'.');
    }

    public function storeMgr(Request $request): RedirectResponse
    {
        $data = $this->validated($request, [
            'contribution_id' => ['required', 'integer'],
        ]);

        [$group, $payer] = $this->ensureEnabled();
        $beneficiary = $this->resolveBeneficiary($request, $group, $payer);
        $activeCycleIds = MerryGoRoundCycle::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $contribution = MerryGoRoundContribution::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $beneficiary->id)
            ->whereIn('merry_go_round_cycle_id', $activeCycleIds)
            ->whereIn('status', ['pending', 'late'])
            ->find((int) $data['contribution_id']);

        if (! $contribution) {
            throw ValidationException::withMessages([
                'contribution_id' => 'Select an outstanding contribution belonging to the selected member.',
            ]);
        }

        $totals = $this->mgr->portalAmount($contribution);
        $manualCharge = $this->manualCharge($data);
        $key = 'ob-mgr-'.$contribution->id.'-'.Str::uuid();

        if ($data['method'] === 'mpesa') {
            return $this->requestMpesa(
                $group,
                $payer,
                $beneficiary,
                'mgr',
                'mgr_contribution',
                $totals['total'],
                [
                    'contribution_id' => $contribution->id,
                    'penalty_amount' => $totals['penalty'],
                    'manual_charge' => $manualCharge,
                ],
                $key,
                $contribution
            );
        }

        try {
            $paid = $this->mgr->payFromWallet(
                $beneficiary,
                $contribution,
                $manualCharge,
                $this->actor(),
                $payer
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->record('mgr', $group, $payer, $beneficiary, $totals['total'], 'wallet', $paid->reference, $contribution);

        return back()->with('status', 'Merry-go-round contribution paid for '.$beneficiary->full_name.'.');
    }

    public function storeWallet(Request $request): RedirectResponse
    {
        $data = $this->validated($request, [
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        [$group, $payer] = $this->ensureEnabled();
        $beneficiary = $this->resolveBeneficiary($request, $group, $payer);
        $amount = Money::toMinor($data['amount']);
        $key = 'ob-wallet-'.$beneficiary->id.'-'.Str::uuid();

        if ($data['method'] === 'mpesa') {
            return $this->requestMpesa(
                $group,
                $payer,
                $beneficiary,
                'wallet',
                'wallet_topup',
                $amount,
                ['manual_charge' => $this->manualCharge($data)],
                $key
            );
        }

        $reference = $this->reference('OBWAL');

        try {
            $this->wallets->transfer($payer, $beneficiary, $amount, $reference, $key);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->record('wallet', $group, $payer, $beneficiary, $amount, 'wallet', $reference);

        return back()->with('status', Money::format($amount).' added to '.$beneficiary->full_name."'s wallet.");
    }

    /**
     * @return array{0: Group, 1: Member}
     */
    private function ensureEnabled(): array
    {
        $group = $this->group();
        $payer = $this->membership();

        abort_unless(OnBehalfPayments::enabled($group), 403, 'Paying on behalf of members is disabled.');
        abort_unless(
            PortalAccess::canAccessSection($group, $payer, 'wallet'),
            403,
            'Your portal does not allow member payments.'
        );

        return [$group, $payer];
    }

    private function resolveBeneficiary(Request $request, Group $group, Member $payer): Member
    {
        $beneficiary = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->find((int) $request->input('beneficiary_id'));

        if (! $beneficiary || $beneficiary->id === $payer->id) {
            throw ValidationException::withMessages([
                'beneficiary_id' => 'Choose another active member in this group.',
            ]);
        }

        return $beneficiary;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        return $request->validate(array_merge([
            'beneficiary_id' => ['required', 'integer'],
            'method' => ['required', Rule::in(['wallet', 'mpesa'])],
            'phone' => ['required_if:method,mpesa', 'nullable', 'string', 'max:30'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ], $rules));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function manualCharge(array $data): ?int
    {
        if (! isset($data['manual_charge']) || $data['manual_charge'] === '') {
            return null;
        }

        return Money::toMinor($data['manual_charge']);
    }

    private function reference(string $prefix): string
    {
        return strtoupper($prefix.'-'.Str::random(10));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestMpesa(
        Group $group,
        Member $payer,
        Member $beneficiary,
        string $type,
        string $purpose,
        int $amount,
        array $payload,
        string $key,
        ?Model $payable = null
    ): RedirectResponse {
        $phone = (string) request('phone');

        try {
            $intent = $this->payments->initiate($beneficiary, [
                'purpose' => $purpose,
                'amount' => $amount,
                'phone' => $phone,
                'payload' => $payload,
            ], $key, $payer);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->record(
            $type,
            $group,
            $payer,
            $beneficiary,
            $amount,
            'mpesa',
            $intent->uuid,
            $payable,
            $intent,
            'pending'
        );

        return back()->with('status', 'Approve the M-Pesa request for '.$beneficiary->full_name.'. Reference: '.$intent->uuid);
    }

    private function record(
        string $type,
        Group $group,
        Member $payer,
        Member $beneficiary,
        int $amount,
        string $method,
        string $reference,
        ?Model $payable = null,
        ?PaymentIntent $intent = null,
        string $status = 'completed'
    ): void {
        OnBehalfPaymentRecord::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'payer_member_id' => $payer->id,
            'member_id' => $beneficiary->id,
            'type' => $type,
            'amount' => $amount,
            'method' => $method,
            'status' => $status,
            'reference' => $reference,
            'payable_type' => $payable?->getMorphClass(),
            'payable_id' => $payable?->getKey(),
            'payment_intent_id' => $intent?->id,
        ]);

        AuditService::log('payment.on_behalf_of', [
            'type' => $type,
            'payer_member_id' => $payer->id,
            'beneficiary_member_id' => $beneficiary->id,
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'payment_intent_id' => $intent?->id,
        ], $group);
    }
}

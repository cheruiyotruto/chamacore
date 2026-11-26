<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Support\GuaranteeSettings;
use App\Support\Money;
use App\Support\PlanAccess;
use Illuminate\Support\Carbon;

class MultiplierEngine
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    /**
     * Compute a member's borrowing capacity with a full, auditable breakdown.
     *
     * @return array<string, mixed>
     */
    public function calculate(
        Member $member,
        LoanProduct $product,
        int $requestedAmount = 0,
        bool $enforceEligibility = true
    ): array {
        $blockers = [];
        $breakdown = [];

        if ($member->group_id !== $product->group_id) {
            $blockers[] = 'The loan product does not belong to the member\'s group.';
        }

        if ($member->status !== 'active') {
            $blockers[] = 'The member account is not active.';
        }

        if ($member->kyc_status !== 'verified') {
            $blockers[] = 'KYC verification is not complete.';
        }

        $joinedAt = $member->joined_at ?? $member->approved_at ?? $member->created_at;
        $membershipDays = $joinedAt ? Carbon::parse($joinedAt)->diffInDays(now()) : 0;
        if ($membershipDays < $product->min_membership_days) {
            $blockers[] = "Membership must be at least {$product->min_membership_days} days old (currently {$membershipDays}).";
        }

        $activeLoans = Loan::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_DISBURSED, Loan::STATUS_RESTRUCTURED])
            ->get();

        $group = $member->group ?? Group::withoutGlobalScopes()->find($member->group_id);
        $maxConcurrentLoans = $group
            ? PlanAccess::maxConcurrentLoans($group, (int) $product->max_concurrent_loans)
            : (int) $product->max_concurrent_loans;

        if ($activeLoans->count() >= $maxConcurrentLoans) {
            $blockers[] = "The member already has the maximum number of active loans ({$maxConcurrentLoans}).";
        }

        if ($product->requires_no_arrears && $activeLoans->contains(fn (Loan $loan) => $loan->isInArrears())) {
            $blockers[] = 'The member has an active loan in arrears.';
        }

        $savingsQuery = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->with('product');

        if (! empty($product->qualifying_savings_product_ids)) {
            $savingsQuery->whereIn('savings_product_id', $product->qualifying_savings_product_ids);
        } else {
            $savingsQuery->whereHas('product', fn ($q) => $q->where('qualifies_for_loan', true)->where('is_active', true));
        }

        $accounts = $savingsQuery->get();

        $eligibleSavings = 0;
        foreach ($accounts as $account) {
            $available = max(0, $account->balance - $account->pledged_amount);
            $eligibleSavings += $available;
            $breakdown[] = [
                'label' => $account->product?->name ?? 'Savings account',
                'amount' => $available,
                'note' => $account->pledged_amount > 0
                    ? 'Pledged amount excluded: '.Money::format($account->pledged_amount)
                    : 'Available savings',
            ];
        }

        $eligibleShares = 0;
        if ($product->include_shares) {
            $shares = SavingsAccount::withoutGlobalScopes()
                ->where('group_id', $member->group_id)
                ->where('member_id', $member->id)
                ->where('status', 'active')
                ->whereHas('product', fn ($q) => $q->where('type', 'shares')->where('is_active', true))
                ->get();

            foreach ($shares as $share) {
                $value = (int) round(max(0, $share->balance - $share->pledged_amount) * $product->share_weight_bps / 10000);
                $eligibleShares += $value;
                $breakdown[] = [
                    'label' => $share->product?->name ?? 'Share capital',
                    'amount' => $value,
                    'note' => 'Weighted at '.number_format($product->share_weight_bps / 100, 2).'%',
                ];
            }
        }

        $base = $eligibleSavings + $eligibleShares;
        $usesSavingsMultiplier = (int) $product->multiplier_bps > 0;

        $breakdown[] = [
            'label' => 'Qualifying base',
            'amount' => $base,
            'note' => 'Savings'.($product->include_shares ? ' + weighted shares' : ''),
        ];

        if ($usesSavingsMultiplier) {
            $grossLimit = (int) floor($base * $product->multiplier_bps / 10000);

            $breakdown[] = [
                'label' => 'Gross limit ('.Money::multiplier($product->multiplier_bps).')',
                'amount' => $grossLimit,
                'note' => 'Mathematical maximum before deductions',
            ];
        } else {
            // Savings multiplier not used (e.g. salary/advance loans): capacity is
            // limited by product/group caps and eligibility rules, not by savings.
            $grossLimit = PHP_INT_MAX;

            $breakdown[] = [
                'label' => 'Savings multiplier',
                'amount' => 0,
                'note' => 'Not applied for this product',
            ];
        }

        $outstandingPrincipal = (int) $activeLoans->sum(fn (Loan $loan) => $loan->outstanding_principal);

        $pendingApplications = (int) Loan::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->whereIn('status', [
                Loan::STATUS_SUBMITTED, Loan::STATUS_GUARANTORS_PENDING,
                Loan::STATUS_UNDER_REVIEW, Loan::STATUS_APPROVED,
            ])
            ->sum('requested_amount');

        $guaranteeCommitments = $this->guaranteeCommitments($member);

        // When savings multiplier is not used, outstanding loans and guarantee
        // commitments should not reduce a savings-derived borrowing limit.
        $deductions = $usesSavingsMultiplier
            ? ($outstandingPrincipal + $pendingApplications + $guaranteeCommitments)
            : 0;

        if ($outstandingPrincipal > 0) {
            $breakdown[] = [
                'label' => 'Outstanding loan principal',
                'amount' => -$outstandingPrincipal,
                'note' => $usesSavingsMultiplier ? 'Deducted' : 'Not deducted (savings multiplier not used)',
            ];
        }
        if ($pendingApplications > 0) {
            $breakdown[] = [
                'label' => 'Pending applications',
                'amount' => -$pendingApplications,
                'note' => $usesSavingsMultiplier ? 'Deducted' : 'Not deducted (savings multiplier not used)',
            ];
        }
        if ($guaranteeCommitments > 0) {
            $breakdown[] = [
                'label' => 'Guarantee commitments',
                'amount' => -$guaranteeCommitments,
                'note' => $usesSavingsMultiplier ? 'Deducted' : 'Not deducted (savings multiplier not used)',
            ];
        }

        $available = $usesSavingsMultiplier ? max(0, $grossLimit - $deductions) : $grossLimit;
        $groupFunds = $this->availableLendingFunds(Group::findOrFail($member->group_id));

        $final = $available;
        $caps = [
            ['label' => 'Product maximum', 'amount' => $product->max_amount],
            ['label' => 'Available group lending funds', 'amount' => $groupFunds],
        ];

        foreach ($caps as $cap) {
            if ($cap['amount'] < $final) {
                $final = $cap['amount'];
                $breakdown[] = ['label' => 'Capped by '.$cap['label'], 'amount' => $final, 'note' => 'Lower of the limits'];
            }
        }

        if ($requestedAmount > 0) {
            $final = min($final, $requestedAmount);
            $breakdown[] = ['label' => 'Requested amount', 'amount' => $requestedAmount, 'note' => 'Applied'];
        }

        $final = max(0, $final);

        if ($final < $product->min_amount && $requestedAmount > 0) {
            $blockers[] = 'The available capacity is below the product minimum of '.Money::format($product->min_amount).'.';
        }

        if ($requestedAmount > 0 && $requestedAmount > $final) {
            $blockers[] = 'The requested amount exceeds the available borrowing capacity.';
        }

        $breakdown[] = ['label' => 'Final eligible amount', 'amount' => $final, 'note' => 'Available to borrow'];

        $reportedGrossLimit = $usesSavingsMultiplier ? $grossLimit : 0;
        $reportedAvailable = $usesSavingsMultiplier ? $available : $final;

        return [
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'membership_days' => $membershipDays,
            'eligible_savings' => $eligibleSavings,
            'remaining_savings' => max(0, $eligibleSavings - $guaranteeCommitments),
            'eligible_shares' => $eligibleShares,
            'qualifying_base' => $base,
            'multiplier_bps' => $product->multiplier_bps,
            'gross_limit' => $reportedGrossLimit,
            'outstanding_principal' => $outstandingPrincipal,
            'pending_applications' => $pendingApplications,
            'guarantee_commitments' => $guaranteeCommitments,
            'total_deductions' => $deductions,
            'available_capacity' => $reportedAvailable,
            'group_available_funds' => $groupFunds,
            'product_min' => $product->min_amount,
            'product_max' => $product->max_amount,
            'requested_amount' => $requestedAmount,
            'final_eligible' => $final,
            'eligible' => empty($blockers) || ! $enforceEligibility,
            'blockers' => $blockers,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * The value of active guarantees the member gave to other loans. These
     * commitments reserve the member's savings and cannot be borrowed again.
     */
    public function guaranteeCommitments(Member $member): int
    {
        $group = Group::withoutGlobalScopes()->find($member->group_id);
        $settings = GuaranteeSettings::for($group);

        return (int) Guarantor::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('guarantor_member_id', $member->id)
            ->whereIn('status', [Guarantor::STATUS_PENDING, Guarantor::STATUS_ACCEPTED])
            ->whereHas('loan', fn ($q) => $q->whereIn('status', [
                Loan::STATUS_SUBMITTED, Loan::STATUS_GUARANTORS_PENDING,
                Loan::STATUS_UNDER_REVIEW, Loan::STATUS_APPROVED,
                Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESTRUCTURED,
            ]))
            ->get()
            ->sum(fn (Guarantor $guarantor) => $settings->commitmentAmount($guarantor));
    }

    /**
     * Qualifying savings still available to the member after reserving the
     * guarantees they gave. Only this remainder may back their own borrowing
     * or a self-guarantee.
     */
    public function remainingSavings(Member $member, LoanProduct $product): int
    {
        return max(0, $this->qualifyingSavings($member, $product) - $this->guaranteeCommitments($member));
    }

    public function guarantorCapacity(Member $guarantor, LoanProduct $product): array
    {
        $base = $this->eligibleBase($guarantor, $product);
        $limit = (int) floor($base * $product->guarantee_multiplier_bps / 10000);

        $settings = GuaranteeSettings::for(
            $guarantor->group ?? Group::withoutGlobalScopes()->find($guarantor->group_id)
        );
        $committed = (int) Guarantor::withoutGlobalScopes()
            ->where('group_id', $guarantor->group_id)
            ->where('guarantor_member_id', $guarantor->id)
            ->whereIn('status', [Guarantor::STATUS_PENDING, Guarantor::STATUS_ACCEPTED])
            ->whereHas('loan', fn ($q) => $q->whereIn('status', [
                Loan::STATUS_SUBMITTED, Loan::STATUS_GUARANTORS_PENDING,
                Loan::STATUS_UNDER_REVIEW, Loan::STATUS_APPROVED,
                Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESTRUCTURED,
            ]))
            ->get()
            ->sum(fn (Guarantor $commitment) => $settings->commitmentAmount($commitment));

        return [
            'base' => $base,
            'limit' => $limit,
            'committed' => $committed,
            'available' => max(0, $limit - $committed),
        ];
    }

    public function availableLendingFunds(Group $group): int
    {
        $cash = $this->ledger->accountBalance($group, LedgerService::CASH);
        $bank = $this->ledger->accountBalance($group, LedgerService::BANK);
        $mgrFunds = $this->ledger->accountBalance($group, LedgerService::MGR_FUNDS);
        $wallets = $this->ledger->accountBalance($group, LedgerService::WALLET_LIABILITY);

        return max(0, $cash + $bank - $mgrFunds - $wallets);
    }

    private function eligibleBase(Member $member, LoanProduct $product): int
    {
        $base = $this->qualifyingSavings($member, $product);

        if ($product->include_shares) {
            $base += (int) SavingsAccount::withoutGlobalScopes()
                ->where('group_id', $member->group_id)
                ->where('member_id', $member->id)
                ->where('status', 'active')
                ->whereHas('product', fn ($q) => $q->where('type', 'shares')->where('is_active', true))
                ->get()
                ->sum(fn (SavingsAccount $account) => (int) round(
                    max(0, $account->balance - $account->pledged_amount) * $product->share_weight_bps / 10000
                ));
        }

        return $base;
    }

    private function qualifyingSavings(Member $member, LoanProduct $product): int
    {
        $query = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->with('product');

        if (! empty($product->qualifying_savings_product_ids)) {
            $query->whereIn('savings_product_id', $product->qualifying_savings_product_ids);
        } else {
            $query->whereHas('product', fn ($q) => $q->where('qualifies_for_loan', true)->where('is_active', true));
        }

        return (int) $query->get()->sum(fn (SavingsAccount $account) => max(0, $account->balance - $account->pledged_amount));
    }
}

<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\InvestmentSchedule;
use App\Models\InvestmentTransaction;
use App\Models\Member;
use App\Models\User;
use App\Services\Investments\InvestmentCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Handles member investment placements, periodic interest accrual and the
 * eventual payout. Money is always held in integer minor units and every
 * movement is mirrored into the double-entry ledger.
 */
class InvestmentService
{
    public function __construct(
        private readonly InvestmentCalculator $calculator,
        private readonly LedgerService $ledger,
        private readonly WalletService $wallets,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @return array{schedule: array<int, array<string, mixed>>, expected_interest: int, expected_total: int, maturity_date: string, period_count: int}
     */
    public function quote(
        InvestmentProduct $product,
        int $amount,
        CarbonInterface|string|null $startDate = null
    ): array {
        $this->assertAmount($product, $amount);

        return $this->calculator->quote($product, $amount, $startDate);
    }

    public function open(
        Member $member,
        InvestmentProduct $product,
        int $amount,
        ?User $actor = null,
        CarbonInterface|string|null $startDate = null,
        string $fundingSource = 'wallet',
        ?string $reference = null,
        ?string $notes = null
    ): Investment {
        if ((int) $product->group_id !== (int) $member->group_id) {
            throw new BusinessRuleException('This investment product does not belong to the member\'s group.');
        }

        if (! $product->is_active) {
            throw new BusinessRuleException('This investment product is not currently open.');
        }

        if ($member->status !== 'active') {
            throw new BusinessRuleException('Only active members can open an investment.');
        }

        $this->assertAmount($product, $amount);

        if (! in_array($fundingSource, ['wallet', 'cash', 'bank'], true)) {
            throw new BusinessRuleException('Select a valid funding source.');
        }

        $start = $startDate instanceof CarbonInterface
            ? CarbonImmutable::instance($startDate)->startOfDay()
            : ($startDate ? CarbonImmutable::parse($startDate)->startOfDay() : CarbonImmutable::today());

        if ($start->isAfter(CarbonImmutable::today()->addDays(31))) {
            throw new BusinessRuleException('The investment start date cannot be more than 31 days in the future.');
        }

        $quote = $this->calculator->quote($product, $amount, $start);
        $reference = $reference ?: 'INV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));

        return DB::transaction(function () use (
            $member, $product, $amount, $actor, $start, $fundingSource, $reference, $notes, $quote
        ) {
            $group = Group::withoutGlobalScopes()->findOrFail($member->group_id);

            $investment = Investment::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'member_id' => $member->id,
                'investment_product_id' => $product->id,
                'reference_no' => $reference,
                'principal' => $amount,
                'interest_rate_bps' => (int) $product->interest_rate_bps,
                'interest_period' => $product->interest_period,
                'interest_method' => $product->interest_method,
                'duration_months' => (int) $product->duration_months,
                'start_date' => $start->toDateString(),
                'maturity_date' => $quote['maturity_date'],
                'expected_interest' => $quote['expected_interest'],
                'accrued_interest' => 0,
                'paid_interest' => 0,
                'status' => Investment::STATUS_ACTIVE,
                'notes' => $notes,
                'terms_snapshot' => [
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'interest_rate_bps' => (int) $product->interest_rate_bps,
                    'interest_period' => $product->interest_period,
                    'interest_method' => $product->interest_method,
                    'duration_months' => (int) $product->duration_months,
                    'early_withdrawal_allowed' => (bool) $product->early_withdrawal_allowed,
                    'early_withdrawal_penalty_bps' => (int) $product->early_withdrawal_penalty_bps,
                ],
                'created_by' => $actor?->id,
            ]);

            foreach ($quote['schedule'] as $period) {
                InvestmentSchedule::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'investment_id' => $investment->id,
                    'period_no' => $period['period_no'],
                    'period_start' => $period['period_start'],
                    'period_end' => $period['period_end'],
                    'due_date' => $period['due_date'],
                    'opening_balance' => $period['opening_balance'],
                    'interest_amount' => $period['interest_amount'],
                    'closing_balance' => $period['closing_balance'],
                    'status' => InvestmentSchedule::STATUS_SCHEDULED,
                ]);
            }

            $journalId = null;

            if ($fundingSource === 'wallet') {
                $walletTransaction = $this->wallets->debit(
                    member: $member,
                    amount: $amount,
                    type: 'investment_deposit',
                    counterAccountCode: LedgerService::INVESTMENT_LIABILITY,
                    reference: $reference,
                    idempotencyKey: 'investment-deposit-'.$reference,
                    description: 'Investment deposit · '.$product->name,
                    source: $investment
                );
                $journalId = $walletTransaction->journal_entry_id;
            } else {
                $counter = $fundingSource === 'bank' ? LedgerService::BANK : LedgerService::CASH;

                $journal = $this->ledger->post($group, 'Investment deposit · '.$product->name, [
                    ['code' => $counter, 'debit' => $amount, 'member_id' => $member->id],
                    ['code' => LedgerService::INVESTMENT_LIABILITY, 'credit' => $amount, 'member_id' => $member->id],
                ], $start, $investment, $reference);
                $journalId = $journal->id;
            }

            InvestmentTransaction::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'investment_id' => $investment->id,
                'member_id' => $member->id,
                'type' => InvestmentTransaction::TYPE_DEPOSIT,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => $amount,
                'reference' => $reference,
                'description' => 'Investment placed · '.$product->name,
                'idempotency_key' => 'investment-tx-deposit-'.$reference,
                'journal_entry_id' => $journalId,
                'transaction_date' => $start->toDateString(),
                'created_by' => $actor?->id,
            ]);

            $this->notifications->send($member, 'investment_opened', [
                'member_name' => $member->full_name,
                'amount' => Money::format($amount),
                'product' => $product->name,
                'maturity_date' => $start->addMonths((int) $product->duration_months)->format('d M Y'),
                'expected_interest' => Money::format($quote['expected_interest']),
            ]);

            AuditService::log('investment.opened', [
                'investment_id' => $investment->id,
                'reference_no' => $investment->reference_no,
                'principal' => $amount,
                'product_id' => $product->id,
            ], $group);

            return $investment->refresh()->load(['product', 'schedules', 'transactions']);
        });
    }

    /**
     * Accrue every interest period whose due date has passed. Returns the
     * amount of interest newly accrued.
     */
    public function accrueDueInterest(Investment $investment): int
    {
        if (! $investment->isOpen()) {
            return 0;
        }

        return DB::transaction(function () use ($investment) {
            $investment = Investment::withoutGlobalScopes()->lockForUpdate()->findOrFail($investment->id);

            if (! $investment->isOpen()) {
                return 0;
            }

            $group = Group::withoutGlobalScopes()->findOrFail($investment->group_id);
            $member = Member::withoutGlobalScopes()->findOrFail($investment->member_id);

            $due = InvestmentSchedule::withoutGlobalScopes()
                ->where('investment_id', $investment->id)
                ->where('status', InvestmentSchedule::STATUS_SCHEDULED)
                ->whereDate('due_date', '<=', now()->toDateString())
                ->orderBy('period_no')
                ->lockForUpdate()
                ->get();

            $accrued = 0;
            $balanceAfter = $investment->principal + $investment->accrued_interest;

            foreach ($due as $period) {
                $interest = (int) $period->interest_amount;

                if ($interest <= 0) {
                    $period->update(['status' => InvestmentSchedule::STATUS_ACCRUED, 'accrued_at' => now()]);

                    continue;
                }

                $reference = 'INTP-'.$investment->reference_no.'-'.$period->period_no;

                $journal = $this->ledger->post(
                    $group,
                    'Investment interest · '.$investment->reference_no,
                    [
                        ['code' => LedgerService::INVESTMENT_INTEREST_EXPENSE, 'debit' => $interest, 'member_id' => $member->id],
                        ['code' => LedgerService::INVESTMENT_LIABILITY, 'credit' => $interest, 'member_id' => $member->id],
                    ],
                    $period->due_date,
                    $investment,
                    $reference
                );

                $balanceAfter += $interest;

                $transaction = InvestmentTransaction::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'investment_id' => $investment->id,
                    'member_id' => $member->id,
                    'type' => InvestmentTransaction::TYPE_INTEREST,
                    'direction' => 'credit',
                    'amount' => $interest,
                    'balance_after' => $balanceAfter,
                    'reference' => $reference,
                    'description' => 'Interest for period '.$period->period_no,
                    'idempotency_key' => 'investment-tx-'.$reference,
                    'journal_entry_id' => $journal->id,
                    'transaction_date' => $period->due_date->toDateString(),
                ]);

                $period->update([
                    'status' => InvestmentSchedule::STATUS_ACCRUED,
                    'accrued_at' => now(),
                    'investment_transaction_id' => $transaction->id,
                ]);

                $accrued += $interest;
            }

            if ($accrued > 0) {
                $investment->update([
                    'accrued_interest' => $investment->accrued_interest + $accrued,
                    'last_accrued_at' => now(),
                ]);
            }

            if ($investment->status === Investment::STATUS_ACTIVE
                && $investment->maturity_date
                && $investment->maturity_date->isPast()) {
                $investment->update(['status' => Investment::STATUS_MATURED]);
            }

            if ($accrued > 0) {
                AuditService::log('investment.interest_accrued', [
                    'investment_id' => $investment->id,
                    'amount' => $accrued,
                ], $group);
            }

            return $accrued;
        });
    }

    public function processDueForMember(Member $member): int
    {
        $investments = Investment::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_MATURED])
            ->get();

        $total = 0;

        foreach ($investments as $investment) {
            $total += $this->accrueDueInterest($investment);
        }

        return $total;
    }

    public function withdraw(
        Investment $investment,
        string $destination = 'wallet',
        ?User $actor = null,
        bool $force = false
    ): InvestmentTransaction {
        $this->accrueDueInterest($investment);

        $investment = Investment::withoutGlobalScopes()->findOrFail($investment->id);

        if (! $investment->isOpen()) {
            throw new BusinessRuleException('This investment has already been settled.');
        }

        if (! in_array($destination, ['wallet', 'cash', 'bank'], true)) {
            throw new BusinessRuleException('Select a valid payout destination.');
        }

        $product = InvestmentProduct::withoutGlobalScopes()->find($investment->investment_product_id);
        $early = $investment->maturity_date && $investment->maturity_date->isFuture();

        if ($early && ! $force && ! ($product?->early_withdrawal_allowed)) {
            throw new BusinessRuleException(
                'This investment cannot be withdrawn before maturity. It matures on '
                .$investment->maturity_date->format('d M Y').'.'
            );
        }

        $penaltyBps = $early ? (int) ($product?->early_withdrawal_penalty_bps ?? 0) : 0;
        $penalty = $this->penalty($investment->accrued_interest, $penaltyBps);
        $totalValue = $investment->principal + $investment->accrued_interest;
        $payout = max(0, $totalValue - $penalty);
        $type = $early ? InvestmentTransaction::TYPE_WITHDRAWAL : InvestmentTransaction::TYPE_MATURITY;
        $reference = ($early ? 'IWDR-' : 'IMAT-').$investment->reference_no;

        return DB::transaction(function () use (
            $investment, $destination, $actor, $penalty, $totalValue, $payout, $type, $reference
        ) {
            $investment = Investment::withoutGlobalScopes()->lockForUpdate()->findOrFail($investment->id);

            if (! $investment->isOpen()) {
                throw new BusinessRuleException('This investment has already been settled.');
            }

            $group = Group::withoutGlobalScopes()->findOrFail($investment->group_id);
            $member = Member::withoutGlobalScopes()->findOrFail($investment->member_id);

            $journalId = null;

            if ($destination === 'wallet') {
                $walletTransaction = $this->wallets->credit(
                    member: $member,
                    amount: $payout,
                    type: $type === InvestmentTransaction::TYPE_MATURITY ? 'investment_maturity' : 'investment_withdrawal',
                    counterAccountCode: LedgerService::INVESTMENT_LIABILITY,
                    reference: $reference,
                    idempotencyKey: 'investment-settle-'.$reference,
                    description: 'Investment payout · '.$investment->reference_no,
                    source: $investment
                );
                $journalId = $walletTransaction->journal_entry_id;
            } else {
                $counter = $destination === 'bank' ? LedgerService::BANK : LedgerService::CASH;
                $lines = [
                    ['code' => LedgerService::INVESTMENT_LIABILITY, 'debit' => $totalValue, 'member_id' => $member->id],
                    ['code' => $counter, 'credit' => $payout, 'member_id' => $member->id],
                ];

                if ($penalty > 0) {
                    $lines[] = ['code' => LedgerService::OTHER_INCOME, 'credit' => $penalty, 'member_id' => $member->id];
                }

                $journal = $this->ledger->post(
                    $group,
                    'Investment payout · '.$investment->reference_no,
                    $lines,
                    now(),
                    $investment,
                    $reference
                );
                $journalId = $journal->id;
            }

            if ($destination === 'wallet' && $penalty > 0) {
                $this->ledger->post($group, 'Early withdrawal penalty · '.$investment->reference_no, [
                    ['code' => LedgerService::INVESTMENT_LIABILITY, 'debit' => $penalty, 'member_id' => $member->id],
                    ['code' => LedgerService::OTHER_INCOME, 'credit' => $penalty, 'member_id' => $member->id],
                ], now(), $investment, $reference.'-PEN');
            }

            $transaction = InvestmentTransaction::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'investment_id' => $investment->id,
                'member_id' => $member->id,
                'type' => $type,
                'direction' => 'debit',
                'amount' => $payout,
                'balance_after' => 0,
                'reference' => $reference,
                'description' => ($type === InvestmentTransaction::TYPE_MATURITY ? 'Maturity payout' : 'Early withdrawal')
                    .($penalty > 0 ? ' (penalty '.Money::format($penalty).')' : ''),
                'idempotency_key' => 'investment-tx-'.$reference,
                'journal_entry_id' => $journalId,
                'transaction_date' => now()->toDateString(),
                'created_by' => $actor?->id,
            ]);

            if ($penalty > 0) {
                InvestmentTransaction::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'investment_id' => $investment->id,
                    'member_id' => $member->id,
                    'type' => InvestmentTransaction::TYPE_PENALTY,
                    'direction' => 'debit',
                    'amount' => $penalty,
                    'balance_after' => 0,
                    'reference' => $reference.'-PEN',
                    'description' => 'Early withdrawal penalty',
                    'transaction_date' => now()->toDateString(),
                    'created_by' => $actor?->id,
                ]);
            }

            $investment->update([
                'status' => Investment::STATUS_WITHDRAWN,
                'paid_interest' => $investment->accrued_interest,
                'closed_at' => now(),
            ]);

            $this->notifications->send($member, $type === InvestmentTransaction::TYPE_MATURITY ? 'investment_matured' : 'investment_withdrawn', [
                'member_name' => $member->full_name,
                'amount' => Money::format($payout),
                'product' => $investment->product?->name ?? 'Investment',
                'reference_no' => $investment->reference_no,
            ]);

            AuditService::log('investment.settled', [
                'investment_id' => $investment->id,
                'type' => $type,
                'principal' => $investment->principal,
                'interest' => $investment->accrued_interest,
                'penalty' => $penalty,
                'payout' => $payout,
            ], $group);

            return $transaction;
        });
    }

    /**
     * @return array{principal:int, accrued_interest:int, current_value:int, active:int, matured:int, invested:int}
     */
    public function summaryFor(Member $member): array
    {
        $investments = Investment::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->get();

        $open = $investments->filter(fn (Investment $investment) => $investment->isOpen());

        return [
            'principal' => (int) $open->sum('principal'),
            'accrued_interest' => (int) $open->sum('accrued_interest'),
            'current_value' => (int) $open->sum(fn (Investment $investment) => $investment->totalValue()),
            'active' => $open->where('status', Investment::STATUS_ACTIVE)->count(),
            'matured' => $open->filter(fn (Investment $investment) => $investment->isMatured())->count(),
            'invested' => $investments->count(),
        ];
    }

    private function assertAmount(InvestmentProduct $product, int $amount): void
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('Investment amount must be greater than zero.');
        }

        $minimum = max(1, (int) $product->min_amount);

        if ($amount < $minimum) {
            throw new BusinessRuleException('The minimum investment for this product is '.Money::format($minimum).'.');
        }

        if ($product->max_amount && $amount > (int) $product->max_amount) {
            throw new BusinessRuleException('The maximum investment for this product is '.Money::format((int) $product->max_amount).'.');
        }
    }

    private function penalty(int $interest, int $penaltyBps): int
    {
        if ($interest <= 0 || $penaltyBps <= 0) {
            return 0;
        }

        try {
            return intdiv($interest * $penaltyBps + 5000, 10000);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }
}

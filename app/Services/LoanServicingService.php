<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\LoanPenaltyCharge;
use App\Models\LoanRestructure;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Loans\LoanCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LoanServicingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly LoanCalculator $calculator,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Record a repayment and allocate it according to the product policy.
     */
    public function recordPayment(
        Loan $loan,
        int $amount,
        string $method,
        string $reference,
        ?string $idempotencyKey = null,
        ?User $receivedBy = null,
        ?string $paidAt = null,
        ?string $notes = null,
        ?Member $payer = null
    ): LoanPayment {
        if ($amount <= 0) {
            throw new BusinessRuleException('Repayment amount must be greater than zero.');
        }

        if (! $loan->isActive()) {
            throw new BusinessRuleException('This loan is not active.');
        }

        if ($payer && $payer->group_id !== $loan->group_id) {
            throw new BusinessRuleException('The payer and borrower must belong to the same group.');
        }

        return DB::transaction(function () use (
            $loan, $amount, $method, $reference, $idempotencyKey, $receivedBy, $paidAt, $notes, $payer
        ) {
            $loan = Loan::withoutGlobalScopes()->lockForUpdate()->findOrFail($loan->id);
            $group = Group::findOrFail($loan->group_id);

            if ($idempotencyKey) {
                $existing = LoanPayment::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $outstanding = $loan->outstandingTotal();
            if ($outstanding <= 0) {
                throw new BusinessRuleException('This loan is already fully settled.');
            }

            if ($method === 'wallet' && $amount > $outstanding) {
                throw new BusinessRuleException('Wallet repayments cannot exceed the outstanding balance.');
            }

            $wallet = null;
            if ($method === 'wallet') {
                $wallet = Wallet::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->where('member_id', $payer?->id ?? $loan->member_id)
                    ->lockForUpdate()
                    ->first();

                if (! $wallet || $wallet->available_balance < $amount) {
                    throw new BusinessRuleException('Insufficient wallet balance for this repayment.');
                }
            }

            $policy = $loan->product->allocationPolicy();
            $remaining = $amount;
            $allocation = ['penalties' => 0, 'fees' => 0, 'interest' => 0, 'principal' => 0, 'overpayment' => 0];

            $schedules = LoanSchedule::withoutGlobalScopes()
                ->where('loan_id', $loan->id)
                ->where('status', '!=', 'paid')
                ->orderBy('due_date')
                ->orderBy('installment_no')
                ->lockForUpdate()
                ->get();

            foreach ($schedules as $schedule) {
                foreach ($policy as $bucket) {
                    if ($remaining <= 0) {
                        break 2;
                    }

                    if (! in_array($bucket, ['penalties', 'fees', 'interest', 'principal'], true)) {
                        continue;
                    }

                    $paid = $this->applyToSchedule($schedule, $bucket, $remaining);

                    if ($paid > 0) {
                        $allocation[$bucket] += $paid;
                        $remaining -= $paid;
                    }
                }

                $this->refreshScheduleStatus($schedule);
            }

            if ($remaining > 0) {
                $allocation['overpayment'] = $remaining;
            }

            $applied = $amount - $remaining;

            $principalApplied = $allocation['principal'];
            $interestApplied = $allocation['interest'];
            $feesApplied = $allocation['fees'];
            $penaltiesApplied = $allocation['penalties'];

            if ($principalApplied > $loan->outstanding_principal
                || $interestApplied > $loan->outstanding_interest
                || $feesApplied > $loan->outstanding_fees
                || $penaltiesApplied > $loan->outstanding_penalties) {
                throw new BusinessRuleException('Allocation would overdraw the loan balance.');
            }

            $ledgerLines = [];

            if ($method === 'wallet') {
                if (! $wallet) {
                    throw new BusinessRuleException('Wallet not found.');
                }
                $ledgerLines[] = ['code' => LedgerService::WALLET_LIABILITY, 'debit' => $applied, 'member_id' => $payer?->id ?? $loan->member_id];
            } else {
                $ledgerLines[] = [
                    'code' => $method === 'bank' ? LedgerService::BANK : LedgerService::CASH,
                    'debit' => $amount,
                    'member_id' => $loan->member_id,
                ];
            }

            if ($principalApplied > 0) {
                $ledgerLines[] = ['code' => LedgerService::LOANS_RECEIVABLE, 'credit' => $principalApplied, 'member_id' => $loan->member_id];
            }
            if ($interestApplied > 0) {
                $ledgerLines[] = ['code' => LedgerService::INTEREST_INCOME, 'credit' => $interestApplied, 'member_id' => $loan->member_id];
            }
            if ($feesApplied > 0) {
                $ledgerLines[] = ['code' => LedgerService::FEES_RECEIVABLE, 'credit' => $feesApplied, 'member_id' => $loan->member_id];
            }
            if ($penaltiesApplied > 0) {
                $ledgerLines[] = ['code' => LedgerService::PENALTY_INCOME, 'credit' => $penaltiesApplied, 'member_id' => $loan->member_id];
            }
            if ($remaining > 0) {
                $ledgerLines[] = ['code' => LedgerService::WALLET_LIABILITY, 'credit' => $remaining, 'member_id' => $loan->member_id];
            }

            if (count($ledgerLines) < 2) {
                throw new BusinessRuleException('The payment could not be allocated.');
            }

            $journal = $this->ledger->post(
                $group,
                'Loan repayment '.$loan->loan_no,
                $ledgerLines,
                $paidAt ?? now(),
                $loan,
                $reference
            );

            $payment = LoanPayment::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'allocation' => $allocation,
                'status' => 'confirmed',
                'paid_at' => $paidAt ?? now(),
                'received_by' => $receivedBy?->id,
                'journal_entry_id' => $journal->id,
                'notes' => $notes,
            ]);

            if ($wallet) {
                $walletBalanceAfter = $wallet->balance - $amount;
                $wallet->update([
                    'balance' => $walletBalanceAfter,
                    'available_balance' => $wallet->available_balance - $amount,
                ]);

                WalletTransaction::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'wallet_id' => $wallet->id,
                    'member_id' => $payer?->id ?? $loan->member_id,
                    'type' => 'loan_repayment',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'balance_after' => $walletBalanceAfter,
                    'status' => 'completed',
                    'reference' => $reference,
                    'idempotency_key' => $idempotencyKey ? $idempotencyKey.'-wallet' : null,
                    'description' => 'Loan repayment '.$loan->loan_no,
                    'related_type' => Loan::class,
                    'related_id' => $loan->id,
                    'journal_entry_id' => $journal->id,
                    'completed_at' => now(),
                ]);
            }

            if ($remaining > 0) {
                $this->creditOverpayment($loan->member, $remaining, $reference, $journal->id);
            }

            $loan->update([
                'outstanding_principal' => $loan->outstanding_principal - $principalApplied,
                'outstanding_interest' => $loan->outstanding_interest - $interestApplied,
                'outstanding_fees' => $loan->outstanding_fees - $feesApplied,
                'outstanding_penalties' => $loan->outstanding_penalties - $penaltiesApplied,
                'amount_paid' => $loan->amount_paid + $applied,
            ]);

            $this->closeIfSettled($loan->refresh());

            AuditService::log('loan.repayment', [
                'loan_id' => $loan->id,
                'amount' => $amount,
                'method' => $method,
                'allocation' => $allocation,
                'payer_member_id' => $payer?->id,
                'borrower_member_id' => $loan->member_id,
            ], $group);

            return $payment->refresh();
        });
    }

    private function applyToSchedule(LoanSchedule $schedule, string $bucket, int $available): int
    {
        if ($bucket === 'penalties') {
            $paid = 0;
            $open = max(0, $schedule->penalty_due - $schedule->penalty_paid);

            if ($open > 0) {
                $portion = min($available, $open);
                $schedule->penalty_paid += $portion;
                $paid += $portion;
                $available -= $portion;
            }

            if ($available <= 0) {
                return $paid;
            }

            $charges = LoanPenaltyCharge::withoutGlobalScopes()
                ->where('installment_id', $schedule->id)
                ->where('status', 'pending')
                ->orderBy('period_start')
                ->get();

            foreach ($charges as $charge) {
                if ($available <= 0) {
                    break;
                }
                $portion = min($available, $charge->amount);
                $charge->update(['status' => 'paid', 'paid_at' => now()]);
                $schedule->penalty_paid += $portion;
                $paid += $portion;
                $available -= $portion;

                if ($portion < $charge->amount) {
                    // Keep the unpaid remainder as a fresh charge for transparency.
                    LoanPenaltyCharge::withoutGlobalScopes()->create([
                        'group_id' => $charge->group_id,
                        'loan_id' => $charge->loan_id,
                        'installment_id' => $charge->installment_id,
                        'amount' => $charge->amount - $portion,
                        'period_start' => $charge->period_end->addDay()->toDateString(),
                        'period_end' => $charge->period_end->toDateString(),
                        'status' => 'pending',
                    ]);
                }
            }

            return $paid;
        }

        $due = match ($bucket) {
            'fees' => max(0, $schedule->fee_due - $schedule->fee_paid),
            'interest' => max(0, $schedule->interest_due - $schedule->interest_paid),
            'principal' => max(0, $schedule->principal_due - $schedule->principal_paid),
            default => 0,
        };

        $portion = min($available, $due);

        if ($portion <= 0) {
            return 0;
        }

        $schedule->{$bucket.'_paid'} += $portion;

        return $portion;
    }

    private function refreshScheduleStatus(LoanSchedule $schedule): void
    {
        $schedule->refresh();
        $due = $schedule->totalDue();
        $paid = $schedule->totalPaid();

        $status = $paid >= $due
            ? 'paid'
            : ($paid > 0 ? 'partial' : ($schedule->due_date->isPast() ? 'overdue' : 'pending'));

        $schedule->update([
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    private function creditOverpayment(Member $member, int $amount, string $reference, int $journalId): void
    {
        $wallet = Wallet::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $member->group_id, 'member_id' => $member->id],
            ['balance' => 0, 'available_balance' => 0, 'status' => 'active']
        );

        $wallet = Wallet::withoutGlobalScopes()->lockForUpdate()->findOrFail($wallet->id);
        $newBalance = $wallet->balance + $amount;

        WalletTransaction::withoutGlobalScopes()->create([
            'group_id' => $member->group_id,
            'wallet_id' => $wallet->id,
            'member_id' => $member->id,
            'type' => 'loan_overpayment',
            'direction' => 'credit',
            'amount' => $amount,
            'balance_after' => $newBalance,
            'status' => 'completed',
            'reference' => $reference,
            'description' => 'Overpayment credited to wallet',
            'journal_entry_id' => $journalId,
            'completed_at' => now(),
        ]);

        $wallet->update([
            'balance' => $newBalance,
            'available_balance' => $wallet->available_balance + $amount,
        ]);
    }

    public function closeIfSettled(Loan $loan): void
    {
        if ($loan->outstandingTotal() > 0 || ! $loan->isActive()) {
            return;
        }

        $loan->update([
            'status' => Loan::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        Guarantor::withoutGlobalScopes()
            ->where('loan_id', $loan->id)
            ->where('status', Guarantor::STATUS_ACCEPTED)
            ->update(['status' => Guarantor::STATUS_RELEASED, 'released_at' => now()]);

        $this->notifications->send($loan->member, 'loan_closed', [
            'member_name' => $loan->member->full_name,
            'loan_no' => $loan->loan_no,
        ]);
    }

    /**
     * Accrue penalties idempotently for every overdue installment.
     */
    public function applyPenalties(Loan $loan, ?CarbonImmutable $asOf = null): int
    {
        if (! $loan->isActive()) {
            return 0;
        }

        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();
        $product = $loan->product;
        $rate = $product->penalty_rate_bps;

        if ($rate <= 0) {
            return 0;
        }

        $created = 0;

        DB::transaction(function () use ($loan, $product, $asOf, $rate, &$created) {
            $schedules = LoanSchedule::withoutGlobalScopes()
                ->where('loan_id', $loan->id)
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->whereDate('due_date', '<', $asOf->toDateString())
                ->lockForUpdate()
                ->get();

            foreach ($schedules as $schedule) {
                $base = max(0, $schedule->total_due - $schedule->principal_paid - $schedule->interest_paid - $schedule->fee_paid - $schedule->penalty_paid);

                if ($base <= 0) {
                    continue;
                }

                $periodStart = $schedule->due_date->copy()->addDays($product->penalty_grace_days);

                if ($periodStart->greaterThanOrEqualTo($asOf)) {
                    continue;
                }

                $periodStart = $periodStart->addDay();

                while ($periodStart->lessThanOrEqualTo($asOf) && $created < 3660) {
                    $periodEnd = match ($product->penalty_period) {
                        'weekly' => $periodStart->addDays(6),
                        'monthly' => $periodStart->addMonthNoOverflow()->subDay(),
                        default => $periodStart,
                    };

                    if ($periodEnd->greaterThan($asOf)) {
                        $periodEnd = $asOf;
                    }

                    $exists = LoanPenaltyCharge::withoutGlobalScopes()
                        ->where('installment_id', $schedule->id)
                        ->whereDate('period_start', $periodStart->toDateString())
                        ->exists();

                    if (! $exists) {
                        LoanPenaltyCharge::withoutGlobalScopes()->create([
                            'group_id' => $loan->group_id,
                            'loan_id' => $loan->id,
                            'installment_id' => $schedule->id,
                            'amount' => intdiv($base * $rate, 10000),
                            'period_start' => $periodStart->toDateString(),
                            'period_end' => $periodEnd->toDateString(),
                            'status' => 'pending',
                        ]);
                        $created++;
                    }

                    $periodStart = $periodEnd->addDay();
                }

                if ($schedule->status !== 'paid') {
                    $schedule->update(['status' => 'overdue']);
                }
            }

            $total = (int) LoanPenaltyCharge::withoutGlobalScopes()
                ->where('loan_id', $loan->id)
                ->where('status', 'pending')
                ->sum('amount');

            $loan->update(['outstanding_penalties' => $total, 'status' => Loan::STATUS_ACTIVE]);

            if ($created > 0) {
                AuditService::log('loan.penalties.accrued', [
                    'loan_id' => $loan->id,
                    'charges_created' => $created,
                    'total_pending' => $total,
                ], Group::find($loan->group_id));
            }
        });

        return $created;
    }

    /**
     * @param  array{duration:int, duration_unit:string, repayment_frequency?:string, interest_rate_bps?:int, reason:string}  $terms
     */
    public function restructure(Loan $loan, array $terms, User $actor): LoanRestructure
    {
        if (! $loan->isActive()) {
            throw new BusinessRuleException('Only active loans can be restructured.');
        }

        if ($loan->outstanding_principal <= 0) {
            throw new BusinessRuleException('This loan has no outstanding principal.');
        }

        return DB::transaction(function () use ($loan, $terms, $actor) {
            $loan = Loan::withoutGlobalScopes()->lockForUpdate()->findOrFail($loan->id);
            $product = $loan->product;

            $oldTerms = [
                'duration' => $loan->duration,
                'duration_unit' => $loan->duration_unit,
                'repayment_frequency' => $loan->repayment_frequency,
                'interest_rate_bps' => $loan->interest_rate_bps,
                'interest_method' => $loan->interest_method,
                'total_repayable' => $loan->total_repayable,
                'outstanding_principal' => $loan->outstanding_principal,
                'outstanding_interest' => $loan->outstanding_interest,
                'maturity_date' => optional($loan->maturity_date)->toDateString(),
            ];

            $newRate = (int) ($terms['interest_rate_bps'] ?? $loan->interest_rate_bps);
            $newFrequency = $terms['repayment_frequency'] ?? $loan->repayment_frequency;
            $duration = (int) $terms['duration'];
            $unit = $terms['duration_unit'];

            $start = CarbonImmutable::now()->startOfDay();
            $quote = $this->calculator->quote([
                'principal' => $loan->outstanding_principal,
                'rate_bps' => $newRate,
                'interest_period' => $loan->interest_period,
                'method' => $loan->interest_method,
                'repayment_frequency' => $newFrequency,
                'duration' => $duration,
                'duration_unit' => $unit,
                'day_count' => $loan->day_count,
                'accrual_mode' => $loan->accrual_mode,
                'start_date' => $start,
            ]);

            // Preserve paid history, withdraw unpaid future installments.
            LoanSchedule::withoutGlobalScopes()
                ->where('loan_id', $loan->id)
                ->where('status', '!=', 'paid')
                ->update(['status' => 'restructured', 'total_due' => 0]);

            $nextInstallment = (int) LoanSchedule::withoutGlobalScopes()->where('loan_id', $loan->id)->max('installment_no');

            foreach ($quote->lines as $line) {
                LoanSchedule::withoutGlobalScopes()->create([
                    'group_id' => $loan->group_id,
                    'loan_id' => $loan->id,
                    'installment_no' => ++$nextInstallment,
                    'due_date' => $line->dueDate->toDateString(),
                    'principal_due' => $line->principalDue,
                    'interest_due' => $line->interestDue,
                    'fee_due' => $line->feeDue,
                    'penalty_due' => 0,
                    'total_due' => $line->totalDue,
                    'principal_paid' => 0,
                    'interest_paid' => 0,
                    'fee_paid' => 0,
                    'penalty_paid' => 0,
                    'status' => 'pending',
                    'metadata' => ['restructured_at' => now()->toDateTimeString(), 'actor_id' => $actor->id],
                ]);
            }

            $restructure = LoanRestructure::withoutGlobalScopes()->create([
                'group_id' => $loan->group_id,
                'loan_id' => $loan->id,
                'old_terms' => $oldTerms,
                'new_terms' => [
                    'duration' => $duration,
                    'duration_unit' => $unit,
                    'repayment_frequency' => $newFrequency,
                    'interest_rate_bps' => $newRate,
                    'quotation' => $quote->toArray(),
                ],
                'reason' => $terms['reason'],
                'effective_date' => $start->toDateString(),
                'status' => 'approved',
                'requested_by' => $actor->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $loan->update([
                'status' => Loan::STATUS_RESTRUCTURED,
                'duration' => $duration,
                'duration_unit' => $unit,
                'repayment_frequency' => $newFrequency,
                'interest_rate_bps' => $newRate,
                'installment_count' => (int) LoanSchedule::withoutGlobalScopes()->where('loan_id', $loan->id)->count(),
                'outstanding_principal' => $loan->outstanding_principal,
                'outstanding_interest' => $quote->totalInterest,
                'outstanding_penalties' => 0,
                'total_interest' => $loan->total_interest + $quote->totalInterest,
                'total_repayable' => $loan->amount_paid + $loan->outstanding_principal + $quote->totalInterest + $loan->outstanding_fees,
                'first_due_date' => $quote->firstDueDate->toDateString(),
                'maturity_date' => $quote->maturityDate->toDateString(),
                'metadata' => array_merge($loan->metadata ?? [], [
                    'restructure_ids' => array_merge($loan->metadata['restructure_ids'] ?? [], [$restructure->id]),
                ]),
            ]);

            LoanPenaltyCharge::withoutGlobalScopes()
                ->where('loan_id', $loan->id)
                ->where('status', 'pending')
                ->update(['status' => 'waived']);

            $this->notifications->send($loan->member, 'loan_restructured', [
                'member_name' => $loan->member->full_name,
                'loan_no' => $loan->loan_no,
            ]);

            AuditService::log('loan.restructured', [
                'loan_id' => $loan->id,
                'restructure_id' => $restructure->id,
                'old_terms' => $oldTerms,
                'reason' => $terms['reason'],
            ], Group::find($loan->group_id));

            return $restructure->refresh();
        });
    }

    public function writeOff(Loan $loan, User $actor, string $reason): Loan
    {
        if (! $loan->isActive()) {
            throw new BusinessRuleException('Only active loans can be written off.');
        }

        return DB::transaction(function () use ($loan, $actor, $reason) {
            $loan = Loan::withoutGlobalScopes()->lockForUpdate()->findOrFail($loan->id);
            $group = Group::findOrFail($loan->group_id);

            $this->ledger->post($group, 'Loan write-off '.$loan->loan_no, [
                ['code' => LedgerService::LOAN_WRITE_OFF, 'debit' => $loan->outstanding_principal, 'member_id' => $loan->member_id],
                ['code' => LedgerService::LOANS_RECEIVABLE, 'credit' => $loan->outstanding_principal, 'member_id' => $loan->member_id],
            ], now(), $loan);

            $loan->update([
                'status' => Loan::STATUS_WRITTEN_OFF,
                'written_off_at' => now(),
                'metadata' => array_merge($loan->metadata ?? [], [
                    'write_off_reason' => $reason,
                    'write_off_by' => $actor->id,
                ]),
            ]);

            AuditService::log('loan.written_off', [
                'loan_id' => $loan->id,
                'principal' => $loan->outstanding_principal,
                'reason' => $reason,
            ], $group);

            return $loan->refresh();
        });
    }

    /**
     * Outstanding balance as at a date, including accrued penalty charges.
     */
    public function payoffAmount(Loan $loan, ?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();

        $this->applyPenalties($loan, $asOf);
        $loan->refresh();

        return $loan->outstandingTotal();
    }
}

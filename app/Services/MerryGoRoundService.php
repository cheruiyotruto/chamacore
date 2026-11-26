<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundCycle;
use App\Models\MerryGoRoundParticipant;
use App\Models\MerryGoRoundPayout;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\MgrSavingsSettings;
use App\Support\Money;
use App\Support\PaymentChargeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerryGoRoundService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
        private readonly PaymentChargeService $charges,
        private readonly SavingsService $savings,
    ) {}

    /**
     * @param  array{name:string, contribution_amount:int, frequency:string, start_date:string, end_date:string, rotation_type?:string, day_of_week?:int|null, day_of_month?:int|null, member_ids:array<int,int>}  $data
     */
    public function createCycle(Group $group, array $data, User $actor): MerryGoRoundCycle
    {
        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($data['end_date'])->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            throw new BusinessRuleException('The cycle end date must be after the start date.');
        }

        $memberIds = array_values(array_unique(array_map('intval', $data['member_ids'] ?? [])));

        if (count($memberIds) < 2) {
            throw new BusinessRuleException('A merry-go-round cycle needs at least two participants.');
        }

        $members = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->whereIn('id', $memberIds)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        if ($members->count() !== count($memberIds)) {
            throw new BusinessRuleException('Every participant must be an active member of this group.');
        }

        if (! in_array($data['frequency'], ['weekly', 'monthly'], true)) {
            throw new BusinessRuleException('Frequency must be weekly or monthly.');
        }

        $dayOfWeek = $data['frequency'] === 'weekly' ? ($data['day_of_week'] ?? null) : null;
        $dayOfMonth = $data['frequency'] === 'monthly' ? ($data['day_of_month'] ?? null) : null;
        $firstDue = $this->firstDueDate($start, $data['frequency'], $dayOfWeek, $dayOfMonth);

        if ($firstDue->greaterThan($end)) {
            throw new BusinessRuleException('The selected collection day falls after the cycle end date.');
        }

        return DB::transaction(function () use ($group, $data, $actor, $start, $end, $firstDue, $memberIds, $dayOfWeek, $dayOfMonth) {
            $cycle = MerryGoRoundCycle::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'name' => $data['name'],
                'contribution_amount' => (int) $data['contribution_amount'],
                'frequency' => $data['frequency'],
                'day_of_week' => $dayOfWeek,
                'day_of_month' => $dayOfMonth,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'rotation_type' => $data['rotation_type'] ?? 'fixed',
                'status' => 'active',
                'penalty_rate_bps' => (int) ($data['penalty_rate_bps'] ?? 0),
                'penalty_grace_days' => (int) ($data['penalty_grace_days'] ?? 0),
                'payout_requires_contributions' => (bool) ($data['payout_requires_contributions'] ?? true),
                'min_contributions_before_payout' => (int) ($data['min_contributions_before_payout'] ?? 1),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $order = $memberIds;

            if (($data['rotation_type'] ?? 'fixed') === 'random') {
                shuffle($order);
            }

            $payoutAmount = $this->effectiveContribution($group, (int) $data['contribution_amount']) * count($order);
            $participantMap = [];

            foreach ($order as $index => $memberId) {
                $payoutDate = $this->addFrequency($firstDue, $data['frequency'], $index);

                $participant = MerryGoRoundParticipant::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'merry_go_round_cycle_id' => $cycle->id,
                    'member_id' => $memberId,
                    'position' => $index + 1,
                    'payout_date' => $payoutDate->toDateString(),
                    'status' => 'active',
                ]);

                MerryGoRoundPayout::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'merry_go_round_cycle_id' => $cycle->id,
                    'participant_id' => $participant->id,
                    'member_id' => $memberId,
                    'amount' => $payoutAmount,
                    'scheduled_date' => $payoutDate->toDateString(),
                    'status' => 'pending',
                ]);

                $participantMap[$memberId] = $participant;
            }

            $dueDates = $this->dueDates($firstDue, $end, $data['frequency']);
            $today = CarbonImmutable::today();

            foreach ($dueDates as $dueDate) {
                foreach ($participantMap as $memberId => $participant) {
                    MerryGoRoundContribution::withoutGlobalScopes()->create([
                        'group_id' => $group->id,
                        'merry_go_round_cycle_id' => $cycle->id,
                        'participant_id' => $participant->id,
                        'member_id' => $memberId,
                        'amount' => (int) $data['contribution_amount'],
                        'due_date' => $dueDate->toDateString(),
                        'status' => $dueDate->lessThan($today) ? 'late' : 'pending',
                    ]);
                }
            }

            AuditService::log('mgr.cycle.created', [
                'cycle_id' => $cycle->id,
                'participants' => count($order),
                'contribution_amount' => $cycle->contribution_amount,
            ], $group);

            return $cycle->refresh();
        });
    }

    /**
     * Update a cycle's schedule and settings while preserving paid records.
     *
     * Unpaid contributions are regenerated against the new schedule; paid
     * contributions and released payouts keep their original amounts and dates.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCycle(MerryGoRoundCycle $cycle, array $data, User $actor): MerryGoRoundCycle
    {
        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($data['end_date'])->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            throw new BusinessRuleException('The cycle end date must be after the start date.');
        }

        if (! in_array($data['frequency'], ['weekly', 'monthly'], true)) {
            throw new BusinessRuleException('Frequency must be weekly or monthly.');
        }

        $dayOfWeek = $data['frequency'] === 'weekly' ? ($data['day_of_week'] ?? null) : null;
        $dayOfMonth = $data['frequency'] === 'monthly' ? ($data['day_of_month'] ?? null) : null;
        $firstDue = $this->firstDueDate($start, $data['frequency'], $dayOfWeek, $dayOfMonth);

        if ($firstDue->greaterThan($end)) {
            throw new BusinessRuleException('The selected collection day falls after the cycle end date.');
        }

        return DB::transaction(function () use ($cycle, $data, $actor, $start, $end, $firstDue, $dayOfWeek, $dayOfMonth) {
            $cycle = MerryGoRoundCycle::withoutGlobalScopes()->lockForUpdate()->findOrFail($cycle->id);
            $group = Group::withoutGlobalScopes()->findOrFail($cycle->group_id);

            if (in_array($cycle->status, ['completed', 'cancelled'], true)) {
                throw new BusinessRuleException('Completed or cancelled cycles cannot be edited.');
            }

            $old = $cycle->only([
                'name', 'contribution_amount', 'frequency', 'day_of_week', 'day_of_month',
                'start_date', 'end_date', 'rotation_type', 'penalty_rate_bps',
                'penalty_grace_days', 'payout_requires_contributions',
                'min_contributions_before_payout', 'notes',
            ]);

            $cycle->update([
                'name' => $data['name'],
                'contribution_amount' => (int) $data['contribution_amount'],
                'frequency' => $data['frequency'],
                'day_of_week' => $dayOfWeek,
                'day_of_month' => $dayOfMonth,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'rotation_type' => $data['rotation_type'] ?? $cycle->rotation_type,
                'penalty_rate_bps' => (int) ($data['penalty_rate_bps'] ?? 0),
                'penalty_grace_days' => (int) ($data['penalty_grace_days'] ?? 0),
                'payout_requires_contributions' => (bool) ($data['payout_requires_contributions'] ?? true),
                'min_contributions_before_payout' => (int) ($data['min_contributions_before_payout'] ?? 1),
                'notes' => $data['notes'] ?? null,
            ]);

            $participants = MerryGoRoundParticipant::withoutGlobalScopes()
                ->where('merry_go_round_cycle_id', $cycle->id)
                ->orderBy('position')
                ->get();

            $contributionAmount = (int) $data['contribution_amount'];
            $payoutAmount = $this->effectiveContribution($group, $contributionAmount) * max(1, $participants->count());

            $paidPayoutParticipantIds = MerryGoRoundPayout::withoutGlobalScopes()
                ->where('merry_go_round_cycle_id', $cycle->id)
                ->where('status', 'paid')
                ->pluck('participant_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($participants as $participant) {
                if (in_array($participant->id, $paidPayoutParticipantIds, true)) {
                    continue;
                }

                $payoutDate = $this->addFrequency($firstDue, $data['frequency'], $participant->position - 1);

                $participant->update(['payout_date' => $payoutDate->toDateString()]);

                MerryGoRoundPayout::withoutGlobalScopes()
                    ->where('merry_go_round_cycle_id', $cycle->id)
                    ->where('participant_id', $participant->id)
                    ->where('status', 'pending')
                    ->update([
                        'scheduled_date' => $payoutDate->toDateString(),
                        'amount' => $payoutAmount,
                    ]);
            }

            $paidContributions = MerryGoRoundContribution::withoutGlobalScopes()
                ->where('merry_go_round_cycle_id', $cycle->id)
                ->where('status', 'paid')
                ->get(['member_id', 'due_date']);

            $paidKeys = $paidContributions
                ->mapWithKeys(fn ($contribution) => [
                    $contribution->member_id.'|'.$contribution->due_date->toDateString() => true,
                ])
                ->all();

            MerryGoRoundContribution::withoutGlobalScopes()
                ->where('merry_go_round_cycle_id', $cycle->id)
                ->where('status', '!=', 'paid')
                ->delete();

            $today = CarbonImmutable::today();

            foreach ($this->dueDates($firstDue, $end, $data['frequency']) as $dueDate) {
                foreach ($participants as $participant) {
                    if (isset($paidKeys[$participant->member_id.'|'.$dueDate->toDateString()])) {
                        continue;
                    }

                    MerryGoRoundContribution::withoutGlobalScopes()->create([
                        'group_id' => $cycle->group_id,
                        'merry_go_round_cycle_id' => $cycle->id,
                        'participant_id' => $participant->id,
                        'member_id' => $participant->member_id,
                        'amount' => $contributionAmount,
                        'due_date' => $dueDate->toDateString(),
                        'status' => $dueDate->lessThan($today) ? 'late' : 'pending',
                    ]);
                }
            }

            AuditService::log('mgr.cycle.updated', [
                'cycle_id' => $cycle->id,
                'before' => $old,
                'after' => $cycle->only(array_keys($old)),
                'updated_by' => $actor->id,
            ], $group);

            return $cycle->refresh();
        });
    }

    /**
     * Record a participant contribution into the group's merry-go-round pool.
     */
    public function recordContribution(
        MerryGoRoundContribution $contribution,
        User $actor,
        string $method = 'cash',
        ?string $reference = null
    ): MerryGoRoundContribution {
        if ($contribution->status === 'paid') {
            throw new BusinessRuleException('This contribution has already been recorded.');
        }

        return DB::transaction(function () use ($contribution, $actor, $method, $reference) {
            $contribution = MerryGoRoundContribution::withoutGlobalScopes()->lockForUpdate()->findOrFail($contribution->id);
            $cycle = MerryGoRoundCycle::withoutGlobalScopes()->findOrFail($contribution->merry_go_round_cycle_id);
            $group = Group::withoutGlobalScopes()->findOrFail($contribution->group_id);
            $member = Member::withoutGlobalScopes()->findOrFail($contribution->member_id);

            if ($cycle->status !== 'active') {
                throw new BusinessRuleException('This merry-go-round cycle is not active.');
            }

            $counter = in_array($method, ['bank', 'mpesa'], true) ? LedgerService::BANK : LedgerService::CASH;
            $allocation = MgrSavingsSettings::for($group)->allocate((int) $contribution->amount);
            $collected = $allocation['mgr'] + $allocation['savings'];
            $contributionReference = $reference ?: 'MGR-'.$contribution->id;

            $journal = $this->ledger->post($group, 'MGR contribution '.$cycle->name, [
                ['code' => $counter, 'debit' => $collected, 'member_id' => $member->id],
                ['code' => LedgerService::MGR_FUNDS, 'credit' => $collected, 'member_id' => $member->id],
            ], now(), $contribution, $contributionReference);

            $savingsTransaction = $this->creditAutoSavings(
                $group,
                $member,
                $contribution,
                $allocation,
                $contributionReference
            );
            $savingsAmount = $savingsTransaction?->amount ?? 0;
            $mgrAmount = max(0, $collected - $savingsAmount);

            $contribution->update([
                'status' => 'paid',
                'paid_at' => now(),
                'reference' => $contributionReference,
                'penalty_amount' => $this->penaltyFor($cycle, $contribution, now()),
                'method' => $method,
                'settlement_amount' => $mgrAmount,
                'savings_transaction_id' => $savingsTransaction?->id,
                'recorded_by' => $actor->id,
            ]);

            MerryGoRoundParticipant::withoutGlobalScopes()
                ->whereKey($contribution->participant_id)
                ->increment('total_contributed', $mgrAmount);

            if ($contribution->penalty_amount > 0) {
                $this->ledger->post($group, 'MGR late contribution fine', [
                    ['code' => $counter, 'debit' => $contribution->penalty_amount, 'member_id' => $member->id],
                    ['code' => LedgerService::PENALTY_INCOME, 'credit' => $contribution->penalty_amount, 'member_id' => $member->id],
                ], now(), $contribution);
            }

            $this->notifications->send($member, 'mgr_contribution_reminder', [
                'cycle' => $cycle->name,
                'amount' => Money::format($contribution->amount),
                'due_date' => $contribution->due_date->format('d M Y'),
            ]);

            AuditService::log('mgr.contribution.recorded', [
                'contribution_id' => $contribution->id,
                'amount' => $contribution->amount,
                'mgr_amount' => $mgrAmount,
                'savings_amount' => $savingsAmount,
                'journal_entry_id' => $journal->id,
            ], $group);

            return $contribution->refresh();
        });
    }

    public function payOut(MerryGoRoundPayout $payout, User $actor, string $method = 'bank'): MerryGoRoundPayout
    {
        if ($payout->status !== 'pending') {
            throw new BusinessRuleException('This payout is not pending.');
        }

        return DB::transaction(function () use ($payout, $actor, $method) {
            $payout = MerryGoRoundPayout::withoutGlobalScopes()->lockForUpdate()->findOrFail($payout->id);
            $cycle = MerryGoRoundCycle::withoutGlobalScopes()->findOrFail($payout->merry_go_round_cycle_id);
            $group = Group::withoutGlobalScopes()->findOrFail($payout->group_id);
            $member = Member::withoutGlobalScopes()->findOrFail($payout->member_id);
            $participant = MerryGoRoundParticipant::withoutGlobalScopes()->findOrFail($payout->participant_id);

            if ($cycle->status !== 'active') {
                throw new BusinessRuleException('This merry-go-round cycle is not active.');
            }

            if ($cycle->payout_requires_contributions) {
                $paid = (int) $cycle->contributions()
                    ->where('member_id', $member->id)
                    ->where('status', 'paid')
                    ->count();

                if ($paid < $cycle->min_contributions_before_payout) {
                    throw new BusinessRuleException(
                        'This participant must complete at least '.$cycle->min_contributions_before_payout.' contribution(s) before payout.'
                    );
                }
            }

            $pool = $this->ledger->accountBalance($group, LedgerService::MGR_FUNDS);

            if ($pool < $payout->amount) {
                throw new BusinessRuleException(
                    'The merry-go-round pool has '.Money::format($pool).' available; '.Money::format($payout->amount).' is required.'
                );
            }

            $journal = $this->ledger->post($group, 'MGR payout '.$cycle->name, [
                ['code' => LedgerService::MGR_FUNDS, 'debit' => $payout->amount, 'member_id' => $member->id],
                ['code' => $method === 'cash' ? LedgerService::CASH : LedgerService::BANK, 'credit' => $payout->amount, 'member_id' => $member->id],
            ], now(), $payout);

            $payout->update([
                'status' => 'paid',
                'paid_at' => now(),
                'journal_entry_id' => $journal->id,
                'reference' => 'MGR-PAYOUT-'.$payout->id,
                'recorded_by' => $actor->id,
            ]);

            $participant->update([
                'status' => 'paid',
                'total_received' => $participant->total_received + $payout->amount,
            ]);

            $this->notifications->send($member, 'mgr_payout', [
                'amount' => Money::format($payout->amount),
                'cycle' => $cycle->name,
            ]);

            $remaining = $cycle->payouts()->where('status', 'pending')->count();

            if ($remaining === 0) {
                $cycle->update(['status' => 'completed']);
            }

            AuditService::log('mgr.payout.paid', [
                'payout_id' => $payout->id,
                'amount' => $payout->amount,
                'journal_entry_id' => $journal->id,
            ], $group);

            return $payout->refresh();
        });
    }

    /**
     * Amount a member must tender for one contribution, including any late
     * penalty accrued so far. Charges are quoted on this total.
     *
     * @return array{amount:int, penalty:int, savings:int, total:int}
     */
    public function portalAmount(MerryGoRoundContribution $contribution): array
    {
        $contribution = MerryGoRoundContribution::withoutGlobalScopes()->findOrFail($contribution->id);
        $cycle = MerryGoRoundCycle::withoutGlobalScopes()->findOrFail($contribution->merry_go_round_cycle_id);
        $group = Group::withoutGlobalScopes()->findOrFail($contribution->group_id);
        $penalty = $this->penaltyFor($cycle, $contribution, now());
        $settings = MgrSavingsSettings::for($group);
        $allocation = $settings->allocate((int) $contribution->amount);

        // Always expose the savings portion for display. Split mode funds it
        // from the contribution itself, so it is not added to the amount the
        // member pays; add-on mode collects it on top of the contribution.
        $savings = $allocation['savings'];
        $extra = $settings->mode() === MgrSavingsSettings::MODE_ADD ? $savings : 0;

        return [
            'amount' => (int) $contribution->amount,
            'penalty' => $penalty,
            'savings' => $savings,
            'total' => (int) $contribution->amount + $penalty + $extra,
        ];
    }

    /**
     * @return array{0: MerryGoRoundContribution, 1: MerryGoRoundCycle, 2: Group}
     */
    public function assertPayableByMember(Member $member, MerryGoRoundContribution $contribution): array
    {
        $contribution = MerryGoRoundContribution::withoutGlobalScopes()->findOrFail($contribution->id);

        if ($contribution->group_id !== $member->group_id || $contribution->member_id !== $member->id) {
            throw new BusinessRuleException('This contribution does not belong to you.');
        }

        $cycle = MerryGoRoundCycle::withoutGlobalScopes()->findOrFail($contribution->merry_go_round_cycle_id);
        $group = Group::withoutGlobalScopes()->findOrFail($contribution->group_id);

        if ($cycle->status !== 'active') {
            throw new BusinessRuleException('This merry-go-round cycle is not active.');
        }

        if (! in_array($contribution->status, ['pending', 'late'], true)) {
            throw new BusinessRuleException('This contribution has already been settled.');
        }

        return [$contribution, $cycle, $group];
    }

    public function payFromWallet(
        Member $member,
        MerryGoRoundContribution $contribution,
        ?int $manualCharge = null,
        ?User $actor = null,
        ?Member $payer = null
    ): MerryGoRoundContribution {
        [$contribution, $cycle, $group] = $this->assertPayableByMember($member, $contribution);
        $payer = $payer ?? $member;

        if ($payer->group_id !== $member->group_id) {
            throw new BusinessRuleException('The payer and beneficiary must belong to the same group.');
        }

        $totals = $this->portalAmount($contribution);
        $wallet = $this->wallet->ensureWallet($payer);

        if ($wallet->status !== 'active') {
            throw new BusinessRuleException('This wallet is not active.');
        }

        $reference = 'MGRP-'.strtoupper(Str::random(10));

        return DB::transaction(function () use (
            $member, $payer, $contribution, $cycle, $group, $totals,
            $manualCharge, $actor, $wallet, $reference
        ) {
            $quote = $this->charges->quote(
                $group,
                $totals['total'],
                PaymentChargeSettings::categoriesFor('mgr_contribution', 'wallet'),
                $manualCharge
            );

            if ((int) $quote['payer_net_out'] > $wallet->available_balance) {
                throw new BusinessRuleException('Insufficient wallet balance to cover this contribution and its charges.');
            }

            $journal = $this->postContributionJournal(
                $group,
                $quote,
                LedgerService::WALLET_LIABILITY,
                $member,
                $totals['penalty'],
                $reference,
                'MGR contribution · '.$cycle->name,
                $contribution,
                $payer
            );

            $this->wallet->recordJournalMovement(
                $payer,
                (int) $quote['payer_net_out'],
                'debit',
                'mgr_contribution',
                $reference,
                $journal,
                'MGR contribution · '.$cycle->name
            );

            $paid = $this->markContributionPaid(
                $contribution,
                $cycle,
                $group,
                $quote,
                $totals['penalty'],
                $reference,
                'wallet',
                $actor,
                null
            );

            if ($payer->id !== $member->id) {
                AuditService::log('mgr.contribution.paid_on_behalf_of', [
                    'contribution_id' => $contribution->id,
                    'payer_member_id' => $payer->id,
                    'beneficiary_member_id' => $member->id,
                    'amount' => $totals['total'],
                    'method' => 'wallet',
                    'reference' => $reference,
                ], $group);
            }

            return $paid;
        });
    }

    public function applyMpesaContribution(
        PaymentIntent $intent,
        MerryGoRoundContribution $contribution,
        string $reference
    ): MerryGoRoundContribution {
        $beneficiary = Member::withoutGlobalScopes()->findOrFail($intent->member_id);
        $payer = $intent->payer_member_id
            ? Member::withoutGlobalScopes()->findOrFail($intent->payer_member_id)
            : $beneficiary;

        [$contribution, $cycle, $group] = $this->assertPayableByMember($beneficiary, $contribution);

        if ($contribution->status === 'paid') {
            if ((int) $contribution->payment_intent_id === (int) $intent->id) {
                return $contribution->refresh();
            }

            throw new BusinessRuleException('This contribution has already been recorded.');
        }

        $quote = data_get($intent->payload, 'charge_quote');

        if (! is_array($quote)) {
            throw new BusinessRuleException('The M-Pesa payment is missing its charge quote.');
        }

        $base = (int) (data_get($intent->payload, 'base_amount') ?? $intent->amount);
        $penalty = (int) data_get($intent->payload, 'penalty_amount', 0);
        $applyAmount = max(0, $base - (int) ($quote['payee_fee'] ?? 0));

        if ($applyAmount <= 0) {
            throw new BusinessRuleException('The M-Pesa payment has no amount to apply.');
        }

        return DB::transaction(function () use (
            $intent, $contribution, $cycle, $group, $quote,
            $penalty, $applyAmount, $reference, $payer
        ) {
            $journal = $this->postContributionJournal(
                $group,
                [...$quote, 'base_amount' => $applyAmount, 'payable_by_payer' => $applyAmount],
                LedgerService::BANK,
                $contribution->member()->firstOrFail(),
                $penalty,
                $reference,
                'MGR contribution · '.$cycle->name,
                $contribution,
                $payer
            );

            $paid = $this->markContributionPaid(
                $contribution,
                $cycle,
                $group,
                $quote,
                $penalty,
                $reference,
                'mpesa',
                null,
                $intent,
                $journal
            );

            if ($payer->id !== $contribution->member_id) {
                AuditService::log('mgr.contribution.paid_on_behalf_of', [
                    'contribution_id' => $contribution->id,
                    'payer_member_id' => $payer->id,
                    'beneficiary_member_id' => $contribution->member_id,
                    'amount' => $applyAmount,
                    'method' => 'mpesa',
                    'reference' => $reference,
                ], $group);
            }

            return $paid;
        });
    }

    /**
     * Post the base contribution and penalty. Charge legs are added by the
     * caller so wallet and M-Pesa flows can share this journal helper.
     *
     * @param  array<string, mixed>  $quote
     */
    private function postContributionJournal(
        Group $group,
        array $quote,
        string $counterCode,
        Member $member,
        int $penalty,
        string $reference,
        string $description,
        MerryGoRoundContribution $contribution,
        ?Member $payer = null
    ) {
        $sourceMember = $payer ?? $member;
        $netToPayee = max(0, (int) ($quote['net_to_payee'] ?? $quote['base_amount']));
        $penaltyCredit = min($penalty, $netToPayee);
        $poolCredit = $netToPayee - $penaltyCredit;

        $lines = [[
            'code' => $counterCode,
            'debit' => (int) ($quote['payable_by_payer'] ?? $quote['base_amount']),
            'member_id' => $sourceMember->id,
            'description' => 'Contribution received',
        ]];

        if ($poolCredit > 0) {
            $lines[] = [
                'code' => LedgerService::MGR_FUNDS,
                'credit' => $poolCredit,
                'member_id' => $member->id,
                'description' => 'Merry-go-round pool',
            ];
        }

        if ($penaltyCredit > 0) {
            $lines[] = [
                'code' => LedgerService::PENALTY_INCOME,
                'credit' => $penaltyCredit,
                'member_id' => $member->id,
                'description' => 'Late contribution penalty',
            ];
        }

        foreach ($this->charges->incomeLines($quote, $group) as $code => $amount) {
            $lines[] = [
                'code' => $code,
                'credit' => $amount,
                'member_id' => null,
                'description' => 'Payment charge income',
            ];
        }

        $groupFee = (int) ($quote['group_fee'] ?? 0);

        if ($groupFee > 0) {
            $lines[] = [
                'code' => LedgerService::OPERATING_EXPENSE,
                'debit' => $groupFee,
                'description' => 'Payment charge borne by group',
            ];
            $lines[] = [
                'code' => $counterCode,
                'credit' => $groupFee,
                'member_id' => $sourceMember->id,
                'description' => 'Payment charge paid by group',
            ];
        }

        return $this->ledger->post($group, $description, $lines, null, $contribution, $reference);
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function markContributionPaid(
        MerryGoRoundContribution $contribution,
        MerryGoRoundCycle $cycle,
        Group $group,
        array $quote,
        int $penalty,
        string $reference,
        string $method,
        ?User $actor,
        ?PaymentIntent $intent,
        $journal = null
    ): MerryGoRoundContribution {
        $locked = MerryGoRoundContribution::withoutGlobalScopes()
            ->lockForUpdate()
            ->findOrFail($contribution->id);

        if ($locked->status === 'paid') {
            if ($intent && (int) $locked->payment_intent_id === (int) $intent->id) {
                return $locked->refresh();
            }

            throw new BusinessRuleException('This contribution has already been recorded.');
        }

        $netToPayee = max(0, (int) ($quote['net_to_payee'] ?? $quote['base_amount']));
        $penaltyCredit = min($penalty, $netToPayee);
        $settlement = $netToPayee - $penaltyCredit;
        $member = Member::withoutGlobalScopes()->findOrFail($locked->member_id);
        $allocation = MgrSavingsSettings::for($group)->allocate((int) $locked->amount);
        $savingsTransaction = $this->creditAutoSavings(
            $group,
            $member,
            $locked,
            $allocation,
            $reference
        );
        $savingsAmount = $savingsTransaction?->amount ?? 0;
        $mgrSettlement = max(0, $settlement - $savingsAmount);

        $locked->update([
            'status' => 'paid',
            'paid_at' => now(),
            'reference' => $reference,
            'penalty_amount' => $penalty,
            'payment_intent_id' => $intent?->id,
            'method' => $method,
            'charge_amount' => (int) ($quote['total_fee'] ?? 0),
            'charge_breakdown' => $quote['lines'] ?? [],
            'borne_by' => $this->borneBy($quote),
            'settlement_amount' => $mgrSettlement,
            'savings_transaction_id' => $savingsTransaction?->id,
            'recorded_by' => $actor?->id,
        ]);

        MerryGoRoundParticipant::withoutGlobalScopes()
            ->whereKey($locked->participant_id)
            ->increment('total_contributed', $mgrSettlement);

        $this->notifications->send($member, 'mgr_contribution_received', [
            'cycle' => $cycle->name,
            'amount' => Money::format($settlement),
            'due_date' => $locked->due_date->format('d M Y'),
            'reference' => $reference,
        ]);

        AuditService::log('mgr.contribution.paid_by_member', [
            'contribution_id' => $locked->id,
            'amount' => $locked->amount,
            'settlement_amount' => $mgrSettlement,
            'savings_amount' => $savingsAmount,
            'charge' => $locked->charge_amount,
            'method' => $method,
            'reference' => $reference,
            'journal_entry_id' => $journal?->id,
        ], $group);

        return $locked->refresh();
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function borneBy(array $quote): string
    {
        foreach ([
            PaymentChargeSettings::BORNE_BY_PAYER,
            PaymentChargeSettings::BORNE_BY_PAYEE,
            PaymentChargeSettings::BORNE_BY_GROUP,
        ] as $option) {
            if (($quote[$option.'_fee'] ?? 0) > 0) {
                return $option;
            }
        }

        return PaymentChargeSettings::BORNE_BY_PAYER;
    }

    /**
     * The amount that actually reaches the MGR pool per member. In split mode
     * the savings portion is taken out of the contribution; in add-on mode it
     * is collected in addition, so the pool still receives the full amount.
     */
    private function effectiveContribution(Group $group, int $amount): int
    {
        return MgrSavingsSettings::for($group)->allocate($amount)['mgr'];
    }

    /**
     * Move the configured portion of a contribution into the member's savings.
     * The savings deposit debits MGR_FUNDS, so the main contribution journal
     * must credit the full collected amount before this runs.
     *
     * @param  array{mgr: int, savings: int}  $allocation
     */
    private function creditAutoSavings(
        Group $group,
        Member $member,
        MerryGoRoundContribution $contribution,
        array $allocation,
        string $reference
    ): ?SavingsTransaction {
        $amount = max(0, (int) ($allocation['savings'] ?? 0));

        if ($amount === 0) {
            return null;
        }

        $account = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if (! $account) {
            $product = SavingsProduct::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->where('is_active', true)
                ->orderByRaw("CASE WHEN type = 'mandatory' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();

            if (! $product) {
                throw new BusinessRuleException(
                    'Automatic MGR savings is enabled but this group has no active savings product.'
                );
            }

            $account = $this->savings->openAccount($member, $product);
        }

        return $this->savings->deposit(
            member: $member,
            account: $account,
            amount: $amount,
            type: 'transfer_in',
            counterAccountCode: LedgerService::MGR_FUNDS,
            reference: $reference,
            idempotencyKey: 'mgr-auto-savings-'.$contribution->id,
            description: 'Automatic savings from MGR contribution',
            source: $contribution,
        );
    }

    public function markOverdue(MerryGoRoundCycle $cycle, ?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();

        return $cycle->contributions()
            ->whereIn('status', ['pending'])
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->update(['status' => 'late']);
    }

    /**
     * The first collection date for a schedule, honouring the chosen weekday
     * (weekly) or day of the month (monthly).
     */
    private function firstDueDate(
        CarbonImmutable $start,
        string $frequency,
        ?int $dayOfWeek,
        ?int $dayOfMonth
    ): CarbonImmutable {
        $first = $start->startOfDay();

        if ($frequency === 'weekly' && $dayOfWeek !== null) {
            $target = max(1, min(7, $dayOfWeek));
            $first = $first->addDays(($target - $first->dayOfWeekIso + 7) % 7);
        }

        if ($frequency === 'monthly' && $dayOfMonth !== null) {
            $target = max(1, min(31, $dayOfMonth));
            $candidate = $first->setDay(min($target, $first->daysInMonth));

            if ($candidate->lessThan($first)) {
                $nextMonth = $first->startOfMonth()->addMonthNoOverflow();
                $candidate = $nextMonth->setDay(min($target, $nextMonth->daysInMonth));
            }

            $first = $candidate;
        }

        return $first;
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function dueDates(CarbonImmutable $start, CarbonImmutable $end, string $frequency): array
    {
        $dates = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $dates[] = $cursor;
            $cursor = $this->addFrequency($cursor, $frequency, 1);
        }

        return $dates;
    }

    private function addFrequency(CarbonImmutable $date, string $frequency, int $periods): CarbonImmutable
    {
        return $frequency === 'weekly'
            ? $date->addWeeks($periods)
            : $date->addMonthsNoOverflow($periods);
    }

    private function penaltyFor(MerryGoRoundCycle $cycle, MerryGoRoundContribution $contribution, \DateTimeInterface $paidAt): int
    {
        if ($cycle->penalty_rate_bps <= 0) {
            return 0;
        }

        $graceEnd = $contribution->due_date->copy()->addDays($cycle->penalty_grace_days);

        if (CarbonImmutable::parse($paidAt)->lessThanOrEqualTo($graceEnd)) {
            return 0;
        }

        return intdiv($contribution->amount * $cycle->penalty_rate_bps, 10000);
    }
}

<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use App\Models\WelfareClaim;
use App\Models\WelfareContribution;
use App\Models\WelfareFund;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class WelfareService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifications,
    ) {
    }

    public function contribute(
        WelfareFund $fund,
        Member $member,
        int $amount,
        User $actor,
        string $method = 'cash',
        ?string $reference = null
    ): WelfareContribution {
        if ($amount <= 0) {
            throw new BusinessRuleException('Welfare contribution must be greater than zero.');
        }

        return DB::transaction(function () use ($fund, $member, $amount, $actor, $method, $reference) {
            if ($fund->group_id !== $member->group_id) {
                throw new BusinessRuleException('The welfare fund does not belong to this group.');
            }

            $group = Group::withoutGlobalScopes()->findOrFail($fund->group_id);
            $counter = $method === 'cash' ? LedgerService::CASH : LedgerService::BANK;

            $journal = $this->ledger->post($group, 'Welfare contribution '.$fund->name, [
                ['code' => $counter, 'debit' => $amount, 'member_id' => $member->id],
                ['code' => LedgerService::WELFARE_FUND, 'credit' => $amount, 'member_id' => $member->id],
            ], now(), $fund, $reference);

            $contribution = WelfareContribution::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'welfare_fund_id' => $fund->id,
                'member_id' => $member->id,
                'amount' => $amount,
                'contribution_date' => now()->toDateString(),
                'reference' => $reference,
                'recorded_by' => $actor->id,
            ]);

            $fund->increment('balance', $amount);

            AuditService::log('welfare.contribution', [
                'fund_id' => $fund->id,
                'member_id' => $member->id,
                'amount' => $amount,
                'journal_entry_id' => $journal->id,
            ], $group);

            return $contribution;
        });
    }

    public function requestClaim(WelfareFund $fund, Member $member, array $data): WelfareClaim
    {
        if ($fund->group_id !== $member->group_id) {
            throw new BusinessRuleException('The welfare fund does not belong to this group.');
        }

        if ((int) $data['amount'] <= 0) {
            throw new BusinessRuleException('Claim amount must be greater than zero.');
        }

        return WelfareClaim::withoutGlobalScopes()->create([
            'group_id' => $fund->group_id,
            'welfare_fund_id' => $fund->id,
            'member_id' => $member->id,
            'type' => $data['type'],
            'amount' => (int) $data['amount'],
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);
    }

    public function approve(WelfareClaim $claim, User $actor, ?string $notes = null): WelfareClaim
    {
        if ($claim->status !== 'pending') {
            throw new BusinessRuleException('Only pending claims can be approved.');
        }

        if ($claim->member->user_id === $actor->id) {
            throw new BusinessRuleException('You cannot approve your own welfare claim.');
        }

        $claim->update([
            'status' => 'approved',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'notes' => $notes,
        ]);

        AuditService::log('welfare.claim.approved', [
            'claim_id' => $claim->id,
            'amount' => $claim->amount,
        ], Group::withoutGlobalScopes()->find($claim->group_id));

        return $claim->refresh();
    }

    public function reject(WelfareClaim $claim, User $actor, string $reason): WelfareClaim
    {
        if ($claim->status !== 'pending') {
            throw new BusinessRuleException('Only pending claims can be rejected.');
        }

        $claim->update([
            'status' => 'rejected',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'notes' => $reason,
        ]);

        return $claim->refresh();
    }

    public function disburse(WelfareClaim $claim, User $actor, string $method = 'cash'): WelfareClaim
    {
        if ($claim->status !== 'approved') {
            throw new BusinessRuleException('Only approved claims can be disbursed.');
        }

        return DB::transaction(function () use ($claim, $actor, $method) {
            $claim = WelfareClaim::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            $fund = WelfareFund::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->welfare_fund_id);
            $group = Group::withoutGlobalScopes()->findOrFail($claim->group_id);

            if ($fund->balance < $claim->amount) {
                throw new BusinessRuleException('The welfare fund has insufficient balance for this claim.');
            }

            $journal = $this->ledger->post($group, 'Welfare claim payout', [
                ['code' => LedgerService::WELFARE_FUND, 'debit' => $claim->amount, 'member_id' => $claim->member_id],
                ['code' => $method === 'cash' ? LedgerService::CASH : LedgerService::BANK, 'credit' => $claim->amount, 'member_id' => $claim->member_id],
            ], now(), $claim);

            $claim->update([
                'status' => 'disbursed',
                'disbursed_by' => $actor->id,
                'disbursed_at' => now(),
            ]);

            $fund->decrement('balance', $claim->amount);

            $this->notifications->send($claim->member, 'welfare_claim', [
                'amount' => Money::format($claim->amount),
                'status' => 'disbursed',
            ]);

            AuditService::log('welfare.claim.disbursed', [
                'claim_id' => $claim->id,
                'amount' => $claim->amount,
                'journal_entry_id' => $journal->id,
            ], $group);

            return $claim->refresh();
        });
    }
}

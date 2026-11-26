<?php

namespace App\Services;

use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\TellerEntry;
use App\Models\TransactionCharge;
use Illuminate\Support\Collection;

/**
 * Resolves which group transaction charges apply to a member and posts the
 * collected charge to the ledger. The underlying transaction services are
 * never modified: charges always post as a separate, balanced journal.
 */
class ChargeService
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    /**
     * Every active charge that applies to this member, in display order.
     *
     * @return Collection<int, TransactionCharge>
     */
    public function applicableForMember(Group $group, Member $member): Collection
    {
        return TransactionCharge::withoutGlobalScopes()
            ->with('members')
            ->where('group_id', $group->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (TransactionCharge $charge) => $charge->appliesToMember($member))
            ->values();
    }

    /**
     * Active charges for one teller category (including wildcard charges) that
     * apply to the member.
     *
     * @return Collection<int, TransactionCharge>
     */
    public function matching(Group $group, string $category, Member $member): Collection
    {
        return $this->applicableForMember($group, $member)
            ->filter(fn (TransactionCharge $charge) => in_array($charge->category, [$category, TransactionCharge::ALL_CATEGORIES], true))
            ->values();
    }

    /**
     * @param  Collection<int, TransactionCharge>  $charges
     * @return array{total:int, waived:int, lines:array<int, array<string, mixed>>}
     */
    public function calculate(Collection $charges, int $baseAmount, bool $waiveOptional, string $direction): array
    {
        $total = 0;
        $waived = 0;
        $lines = [];

        foreach ($charges as $charge) {
            $potential = $charge->calculatedAmount($baseAmount);

            if ($potential <= 0) {
                continue;
            }

            $applied = $charge->is_mandatory || ! $waiveOptional;

            if ($applied && $direction === TellerEntry::DIRECTION_OUT) {
                // Never let a payout charge exceed the amount being paid out.
                $potential = min($potential, max(0, $baseAmount - $total));
            }

            if ($applied && $potential <= 0) {
                continue;
            }

            if (! $applied) {
                $waived += $potential;
            } else {
                $total += $potential;
            }

            $lines[] = [
                'charge_id' => $charge->id,
                'name' => $charge->name,
                'basis' => $charge->basis,
                'applied' => $applied,
                'amount' => $applied ? $potential : 0,
                'potential' => $potential,
                'income_account_code' => $charge->income_account_code,
            ];
        }

        return ['total' => $total, 'waived' => $waived, 'lines' => $lines];
    }

    /**
     * Post the collected charge. Cash-in charges are debited to the counter
     * account on top of the transaction; cash-out charges reduce the payout.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function post(
        Group $group,
        Member $member,
        TellerEntry $entry,
        string $counterCode,
        int $total,
        array $lines,
        ?string $reference,
    ): ?JournalEntry {
        if ($total <= 0) {
            return null;
        }

        $ledgerLines = [[
            'code' => $counterCode,
            'debit' => $total,
            'member_id' => $member->id,
            'description' => 'Teller charge collected',
        ]];

        foreach ($lines as $line) {
            $amount = (int) ($line['amount'] ?? 0);

            if ($amount <= 0) {
                continue;
            }

            $ledgerLines[] = [
                'code' => (string) $line['income_account_code'],
                'credit' => $amount,
                'member_id' => $member->id,
                'description' => 'Teller charge · '.$line['name'],
            ];
        }

        if (count($ledgerLines) < 2) {
            return null;
        }

        return $this->ledger->post(
            $group,
            'Teller charges · '.$entry->receipt_no,
            $ledgerLines,
            null,
            $entry,
            $reference ?: null,
        );
    }
}

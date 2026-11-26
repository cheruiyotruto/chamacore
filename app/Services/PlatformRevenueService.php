<?php

namespace App\Services;

use App\Models\Group;
use App\Models\LedgerAccount;
use App\Models\LedgerLine;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Platform-side reporting for organisations marked as platform-managed.
 *
 * Group books are not rewritten: revenue is derived from posted income accounts
 * in those tenants and presented alongside subscription income.
 */
class PlatformRevenueService
{
    /**
     * @return array{groupIncome:int, subscriptionIncome:int, totalIncome:int, groups:Collection, accounts:Collection}
     */
    public function summary(): array
    {
        $managedIds = Group::platformManagedGroupIds();
        $groupIncome = $this->incomeFor($managedIds);
        $subscriptionIncome = (int) SubscriptionInvoice::query()
            ->whereIn('status', ['paid'])
            ->sum('amount');

        $groups = Group::withoutGlobalScopes()
            ->whereIn('platform_management_mode', [
                Group::PLATFORM_MODE_OVERSIGHT,
                Group::PLATFORM_MODE_FULL,
            ])
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(function (Group $group) {
                $ids = collect($group->visibleGroupIds())
                    ->filter(fn (int $id) => Group::withoutGlobalScopes()->find($id)?->isPlatformManaged())
                    ->values()
                    ->all();

                return [
                    'group' => $group,
                    'mode' => $group->platformManagementLabel(),
                    'income' => $this->incomeFor($ids),
                    'group_count' => count($ids),
                ];
            });

        return [
            'groupIncome' => $groupIncome,
            'subscriptionIncome' => $subscriptionIncome,
            'totalIncome' => $groupIncome + $subscriptionIncome,
            'groups' => $groups,
            'accounts' => $this->incomeByAccount($managedIds),
        ];
    }

    public function incomeForGroup(Group $group): int
    {
        $ids = collect($group->visibleGroupIds())
            ->filter(fn (int $id) => Group::withoutGlobalScopes()->find($id)?->isPlatformManaged())
            ->values()
            ->all();

        return $this->incomeFor($ids);
    }

    /**
     * @param  array<int, int>  $groupIds
     */
    private function incomeFor(array $groupIds): int
    {
        if (! $groupIds) {
            return 0;
        }

        return (int) $this->incomeQuery($groupIds)->sum('ledger_lines.credit');
    }

    /**
     * @param  array<int, int>  $groupIds
     */
    private function incomeByAccount(array $groupIds): Collection
    {
        if (! $groupIds) {
            return collect();
        }

        return $this->incomeQuery($groupIds)
            ->select(
                'ledger_accounts.code',
                'ledger_accounts.name',
                DB::raw('sum(ledger_lines.credit) as total')
            )
            ->groupBy('ledger_accounts.code', 'ledger_accounts.name')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * @param  array<int, int>  $groupIds
     */
    private function incomeQuery(array $groupIds)
    {
        return LedgerLine::withoutGlobalScopes()
            ->join('ledger_accounts', 'ledger_lines.ledger_account_id', '=', 'ledger_accounts.id')
            ->join('journal_entries', 'ledger_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereIn('ledger_lines.group_id', $groupIds)
            ->where('ledger_accounts.type', LedgerAccount::TYPE_INCOME)
            ->where('journal_entries.status', 'posted');
    }
}

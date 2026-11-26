<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\Wallet;
use App\Services\AuditService;
use App\Services\LedgerService;
use Illuminate\Console\Command;

class ReconcileLedgers extends Command
{
    protected $signature = 'chama:reconcile-ledgers
                            {--group= : Restrict the run to one group ID}
                            {--strict : Return a failure exit code when any operational balance differs}';

    protected $description = 'Verify double-entry journals and compare control accounts with operational balances.';

    public function handle(LedgerService $ledger): int
    {
        $groups = Group::withoutGlobalScopes()
            ->where('status', 'active')
            ->when($this->option('group'), fn ($query, $groupId) => $query->whereKey((int) $groupId))
            ->orderBy('id')
            ->get();

        if ($groups->isEmpty()) {
            $this->warn('No active groups matched this run.');

            return self::SUCCESS;
        }

        $rows = [];
        $imbalanced = 0;
        $differences = 0;

        foreach ($groups as $group) {
            $debits = $ledger->totalDebits($group);
            $credits = $ledger->totalCredits($group);
            $groupDifferences = 0;

            if ($debits !== $credits) {
                $imbalanced++;
                $rows[] = [$group->name, 'Journal debits vs credits', $debits, $credits, $debits - $credits];
            }

            $checks = [
                'Savings liability vs member savings' => [
                    $ledger->accountBalance($group, LedgerService::SAVINGS_LIABILITY),
                    (int) SavingsAccount::withoutGlobalScopes()->where('group_id', $group->id)->sum('balance'),
                ],
                'Wallet liability vs member wallets' => [
                    $ledger->accountBalance($group, LedgerService::WALLET_LIABILITY),
                    (int) Wallet::withoutGlobalScopes()->where('group_id', $group->id)->sum('balance'),
                ],
                'Loans receivable vs outstanding principal' => [
                    $ledger->accountBalance($group, LedgerService::LOANS_RECEIVABLE),
                    (int) Loan::withoutGlobalScopes()
                        ->where('group_id', $group->id)
                        ->whereIn('status', [
                            Loan::STATUS_DISBURSED,
                            Loan::STATUS_ACTIVE,
                            Loan::STATUS_RESTRUCTURED,
                        ])
                        ->sum('outstanding_principal'),
                ],
                'MGR funds vs paid contributions less payouts' => [
                    $ledger->accountBalance($group, LedgerService::MGR_FUNDS),
                    $this->merryGoRoundBalance($group),
                ],
            ];

            foreach ($checks as $label => [$ledgerValue, $operationalValue]) {
                if ($ledgerValue === $operationalValue) {
                    continue;
                }

                $differences++;
                $groupDifferences++;
                $rows[] = [$group->name, $label, $ledgerValue, $operationalValue, $ledgerValue - $operationalValue];
            }

            AuditService::log('ledger.reconciled', [
                'debits' => $debits,
                'credits' => $credits,
                'imbalanced' => $debits !== $credits,
                'differences' => $groupDifferences,
            ], $group);
        }

        if ($rows === []) {
            $this->info(sprintf('All %d group ledger(s) reconcile.', $groups->count()));

            return self::SUCCESS;
        }

        $this->table(
            ['Group', 'Check', 'Ledger', 'Operational', 'Difference'],
            $rows
        );

        $this->warn(sprintf(
            'Found %d imbalance(s) and %d control-account difference(s).',
            $imbalanced,
            $differences
        ));

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function merryGoRoundBalance(Group $group): int
    {
        $paidContributions = (int) \App\Models\MerryGoRoundContribution::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'paid')
            ->sum('amount');

        $paidPayouts = (int) \App\Models\MerryGoRoundPayout::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'paid')
            ->sum('amount');

        return $paidContributions - $paidPayouts;
    }
}

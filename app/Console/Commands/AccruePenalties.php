<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Loan;
use App\Services\LoanServicingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AccruePenalties extends Command
{
    protected $signature = 'chama:accrue-penalties
                            {--date= : Accrual date (Y-m-d), defaults to today}
                            {--group= : Restrict the run to one group ID}';

    protected $description = 'Accrue daily, weekly or monthly penalties on overdue loan installments.';

    public function handle(LoanServicingService $servicing): int
    {
        $asOf = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'))
            : CarbonImmutable::now();

        $groups = Group::withoutGlobalScopes()
            ->where('status', 'active')
            ->when($this->option('group'), fn ($query, $groupId) => $query->whereKey((int) $groupId))
            ->orderBy('id')
            ->get();

        if ($groups->isEmpty()) {
            $this->warn('No active groups matched this run.');

            return self::SUCCESS;
        }

        $loanCount = 0;
        $chargeCount = 0;

        foreach ($groups as $group) {
            Loan::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereIn('status', [
                    Loan::STATUS_DISBURSED,
                    Loan::STATUS_ACTIVE,
                    Loan::STATUS_RESTRUCTURED,
                ])
                ->orderBy('id')
                ->chunkById(200, function ($loans) use ($servicing, $asOf, &$loanCount, &$chargeCount) {
                    foreach ($loans as $loan) {
                        $chargeCount += $servicing->applyPenalties($loan, $asOf);
                        $loanCount++;
                    }
                });
        }

        $this->info(sprintf(
            'Accrued %d penalty charge(s) across %d loan(s) as at %s.',
            $chargeCount,
            $loanCount,
            $asOf->toDateString()
        ));

        return self::SUCCESS;
    }
}

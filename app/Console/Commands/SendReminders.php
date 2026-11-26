<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\MerryGoRoundCycle;
use App\Services\MerryGoRoundService;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'chama:send-reminders
                            {--group= : Restrict the run to one group ID}
                            {--date= : Send date in Y-m-d format (defaults to today)}
                            {--days= : Legacy option retained for compatibility; configure lead days in the admin UI}';

    protected $description = 'Send admin-configured date reminders for merry-go-round, loans, bills and custom notices.';

    public function handle(
        MerryGoRoundService $merryGoRound,
        ReminderService $reminders
    ): int {
        $dateInput = $this->option('date');

        try {
            $date = $dateInput ? CarbonImmutable::parse($dateInput)->startOfDay() : CarbonImmutable::today();
        } catch (\Throwable $e) {
            $this->error('Invalid --date value. Use Y-m-d.');

            return self::FAILURE;
        }

        $groups = Group::withoutGlobalScopes()
            ->where('status', 'active')
            ->when($this->option('group'), fn ($query, $groupId) => $query->whereKey((int) $groupId))
            ->orderBy('id')
            ->get();

        if ($groups->isEmpty()) {
            $this->warn('No active groups matched this run.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            // Keep overdue contribution statuses accurate before resolving due rules.
            MerryGoRoundCycle::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->where('status', 'active')
                ->each(fn (MerryGoRoundCycle $cycle) => $merryGoRound->markOverdue($cycle, $date));

            $result = $reminders->sendDue($group, $date);
            $sent += $result['sent'];
            $skipped += $result['skipped'];
        }

        $this->info("Sent {$sent} reminder(s); {$skipped} already sent for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}

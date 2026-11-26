<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Subscription;
use App\Services\AuditService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class RenewSubscriptions extends Command
{
    protected $signature = 'chama:renew-subscriptions
                            {--group= : Restrict the run to one group ID}
                            {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Renew expired subscriptions that auto-renew and expire plans that do not.';

    public function handle(SubscriptionService $subscriptions): int
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

        $renewed = 0;
        $expired = 0;

        foreach ($groups as $group) {
            $due = Subscription::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereIn('status', ['active', 'trialing'])
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', now())
                ->with('plan')
                ->orderBy('ends_at')
                ->get();

            foreach ($due as $subscription) {
                if (! $subscription->plan) {
                    continue;
                }

                if (! $subscription->auto_renew) {
                    if ($this->option('dry-run')) {
                        $this->line("[dry-run] expire subscription {$subscription->id} for {$group->name}");
                        continue;
                    }

                    $subscription->update(['status' => 'expired']);
                    $group->update(['subscription_status' => 'expired']);

                    AuditService::log('subscription.expired', [
                        'subscription_id' => $subscription->id,
                        'plan_id' => $subscription->plan_id,
                    ], $group);

                    $expired++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("[dry-run] renew {$group->name} on {$subscription->plan->name}");
                    continue;
                }

                // The service cancels the old term, opens a fresh term and issues
                // an invoice. Running again tomorrow is safe because the new
                // subscription ends in the future.
                $subscriptions->subscribe($group, $subscription->plan);
                $renewed++;
            }
        }

        $this->info("Renewed {$renewed} subscription(s); expired {$expired}.");

        return self::SUCCESS;
    }
}

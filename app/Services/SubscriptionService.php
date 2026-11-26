<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function startTrial(Group $group, Plan $plan): Subscription
    {
        return DB::transaction(function () use ($group, $plan) {
            $trialEnds = CarbonImmutable::now()->addDays($plan->trial_days);

            $subscription = Subscription::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'starts_at' => now(),
                'trial_ends_at' => $trialEnds,
                'ends_at' => $trialEnds,
                'auto_renew' => true,
            ]);

            $group->update([
                'subscription_status' => 'trialing',
                'trial_ends_at' => $trialEnds,
            ]);

            return $subscription;
        });
    }

    public function subscribe(Group $group, Plan $plan): Subscription
    {
        if (! $plan->is_active) {
            throw new BusinessRuleException('This plan is not available.');
        }

        $owner = $group->billingOwner();

        Subscription::withoutGlobalScopes()
            ->where('group_id', $owner->id)
            ->whereIn('status', ['active', 'trialing'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        // A group gets one trial across all plans. The renewal command sees the
        // historical trial row and converts it into the first paid term.
        $trialAlreadyUsed = Subscription::withoutGlobalScopes()
            ->where('group_id', $owner->id)
            ->whereNotNull('trial_ends_at')
            ->exists();

        if ((int) $plan->price > 0 && (int) $plan->trial_days > 0 && ! $trialAlreadyUsed) {
            $subscription = $this->startTrial($owner, $plan);

            AuditService::log('subscription.trial_started', [
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'trial_ends_at' => $subscription->trial_ends_at?->toDateTimeString(),
            ], $owner);

            return $subscription;
        }

        return DB::transaction(function () use ($owner, $plan) {
            $free = (int) $plan->price === 0;
            $starts = CarbonImmutable::now();

            $subscription = Subscription::withoutGlobalScopes()->create([
                'group_id' => $owner->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => $starts,
                'ends_at' => $free ? null : $this->addInterval($starts, $plan->interval),
                'auto_renew' => ! $free,
            ]);

            $owner->update([
                'subscription_status' => 'active',
                'trial_ends_at' => null,
            ]);

            if (! $free) {
                $this->issueInvoice($subscription);
            }

            AuditService::log($free ? 'subscription.free_assigned' : 'subscription.started', [
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
            ], $owner);

            return $subscription;
        });
    }

    public function issueInvoice(Subscription $subscription): SubscriptionInvoice
    {
        $sequence = SubscriptionInvoice::withoutGlobalScopes()
            ->where('group_id', $subscription->group_id)
            ->whereYear('issue_date', now()->year)
            ->count() + 1;

        $invoice = SubscriptionInvoice::withoutGlobalScopes()->create([
            'group_id' => $subscription->group_id,
            'subscription_id' => $subscription->id,
            'invoice_no' => 'SUB-'.now()->format('Y').'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'amount' => $subscription->plan->price,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'unpaid',
        ]);

        $creator = $subscription->group->creator;

        if ($creator) {
            app(NotificationService::class)->notifyUser(
                $creator,
                'subscription_invoice',
                'Subscription invoice '.$invoice->invoice_no,
                'Your subscription invoice for '.Money::format($invoice->amount).' is due on '.$invoice->due_date->format('d M Y').'.'
            );
        }

        return $invoice;
    }

    public function recordPayment(
        SubscriptionInvoice $invoice,
        int $amount,
        string $method,
        string $reference,
        User $actor
    ): SubscriptionPayment {
        if ($invoice->status === 'paid') {
            throw new BusinessRuleException('This invoice is already paid.');
        }

        if ($amount < $invoice->amount) {
            throw new BusinessRuleException('The payment must settle the full invoice amount.');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $actor) {
            $payment = SubscriptionPayment::withoutGlobalScopes()->create([
                'group_id' => $invoice->group_id,
                'subscription_invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'paid_at' => now(),
                'recorded_by' => $actor->id,
            ]);

            $invoice->update(['status' => 'paid', 'paid_at' => now()]);

            $subscription = $invoice->subscription;
            $subscription->update([
                'status' => 'active',
                'ends_at' => $this->addInterval(CarbonImmutable::now(), $subscription->plan->interval),
            ]);

            $subscription->group->update(['subscription_status' => 'active']);

            AuditService::log('subscription.payment', [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => $method,
            ], $subscription->group);

            return $payment;
        });
    }

    public function cancel(Subscription $subscription, User $actor): Subscription
    {
        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);

        $subscription->group->update(['subscription_status' => 'cancelled']);

        AuditService::log('subscription.cancelled', [
            'subscription_id' => $subscription->id,
        ], $subscription->group);

        return $subscription->refresh();
    }

    private function addInterval(CarbonImmutable $date, string $interval): CarbonImmutable
    {
        return match ($interval) {
            'quarterly' => $date->addMonthsNoOverflow(3),
            'annual' => $date->addMonthsNoOverflow(12),
            default => $date->addMonthNoOverflow(),
        };
    }
}

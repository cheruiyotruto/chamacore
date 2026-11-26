<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionRenewalCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expired_trial_is_renewed_and_invoiced(): void
    {
        $admin = User::factory()->create();

        $plan = Plan::create([
            'name' => 'Growth',
            'slug' => 'growth',
            'price' => 750_000,
            'interval' => 'monthly',
            'trial_days' => 30,
            'max_groups' => 1,
            'max_members' => 300,
            'modules' => ['core', 'loans'],
            'is_active' => true,
        ]);

        $group = Group::factory()->create([
            'created_by' => $admin->id,
            'status' => 'active',
            'subscription_status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);

        $subscription = Subscription::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'starts_at' => now()->subMonth(),
            'trial_ends_at' => now()->subDay(),
            'ends_at' => now()->subDay(),
            'auto_renew' => true,
        ]);

        $this->artisan('chama:renew-subscriptions')->assertSuccessful();

        $this->assertSame('cancelled', $subscription->refresh()->status);
        $this->assertSame('active', $group->refresh()->subscription_status);

        $new = Subscription::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($new);
        $this->assertTrue($new->ends_at->isFuture());
        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()
            ->where('subscription_id', $new->id)
            ->count());
    }

    public function test_a_non_renewing_subscription_expires(): void
    {
        $admin = User::factory()->create();

        $plan = Plan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'price' => 250_000,
            'interval' => 'monthly',
            'trial_days' => 30,
            'max_groups' => 1,
            'max_members' => 50,
            'modules' => ['core'],
            'is_active' => true,
        ]);

        $group = Group::factory()->create([
            'created_by' => $admin->id,
            'subscription_status' => 'active',
        ]);

        $subscription = Subscription::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
            'auto_renew' => false,
        ]);

        $this->artisan('chama:renew-subscriptions')->assertSuccessful();

        $this->assertSame('expired', $subscription->refresh()->status);
        $this->assertSame('expired', $group->refresh()->subscription_status);
    }
}

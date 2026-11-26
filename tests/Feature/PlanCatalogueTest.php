<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\PaymentChargeService;
use App\Services\SubscriptionService;
use App\Support\PaymentChargeSettings;
use App\Support\PlanAccess;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_catalogue_has_five_plans_with_the_new_trial_rules(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertSame(5, Plan::count());

        $paid = Plan::where('slug', 'starter_savings_circle_basic')->firstOrFail();
        $this->assertSame(100_000, (int) $paid->price);
        $this->assertSame(30, (int) $paid->trial_days);
        $this->assertContains(PlanAccess::SINGLE_LOAN, $paid->modules);
        $this->assertContains(PlanAccess::WALLET_MEMBERS_OFFICIALS, $paid->modules);

        $free = Plan::where('slug', 'platform_managed_free')->firstOrFail();
        $this->assertSame(0, (int) $free->price);
        $this->assertSame(0, (int) $free->trial_days);
        $this->assertContains(PlanAccess::PLATFORM_MANAGED, $free->modules);
        $this->assertContains(PlanAccess::PLATFORM_CHARGES, $free->modules);
    }

    public function test_a_paid_start_starts_with_one_month_free_then_invoices_the_first_paid_term(): void
    {
        $group = Group::factory()->create();
        $plan = $this->plan([
            'slug' => 'starter_savings_circle_basic',
            'price' => 100_000,
            'trial_days' => 30,
        ]);

        $trial = app(SubscriptionService::class)->subscribe($group, $plan);

        $this->assertSame('trialing', $trial->status);
        $this->assertSame(30, abs((int) $trial->trial_ends_at->diffInDays($trial->starts_at)));
        $this->assertSame(0, SubscriptionInvoice::count());

        $trial->update([
            'trial_ends_at' => now()->subDay(),
            'ends_at' => now()->subDay(),
        ]);

        $this->artisan('chama:renew-subscriptions')->assertSuccessful();

        $paid = Subscription::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->firstOrFail();

        $this->assertSame(100_000, (int) $paid->invoices()->firstOrFail()->amount);
        $this->assertTrue($paid->ends_at->isFuture());
    }

    public function test_the_free_plan_is_active_without_an_invoice(): void
    {
        $group = Group::factory()->create();
        $plan = $this->plan([
            'slug' => 'platform_managed_free',
            'price' => 0,
            'trial_days' => 0,
            'modules' => ['core', 'platform_managed', 'platform_charges'],
        ]);

        $subscription = app(SubscriptionService::class)->subscribe($group, $plan);

        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->ends_at);
        $this->assertFalse($subscription->auto_renew);
        $this->assertSame(0, SubscriptionInvoice::count());
    }

    public function test_plan_flags_control_loan_limits_and_platform_management(): void
    {
        $paidPlan = $this->plan([
            'slug' => 'starter_savings_circle_basic',
            'modules' => ['core', 'savings', 'wallet', 'single_loan', 'wallet_members_officials'],
        ]);
        $freePlan = $this->plan([
            'slug' => 'platform_managed_free',
            'price' => 0,
            'modules' => ['core', 'platform_managed', 'platform_charges'],
        ]);

        $paidGroup = Group::factory()->create();
        $freeGroup = Group::factory()->create();
        $this->subscription($paidGroup, $paidPlan);
        $this->subscription($freeGroup, $freePlan);

        $this->assertSame(1, PlanAccess::maxConcurrentLoans($paidGroup, 5));
        $this->assertSame(5, PlanAccess::maxConcurrentLoans($freeGroup, 5));
        $this->assertTrue(PlanAccess::hasModule($paidGroup, 'wallet'));
        $this->assertFalse(PlanAccess::hasModule($paidGroup, 'accounting'));
        $this->assertTrue(PlanAccess::isPlatformManaged($freeGroup));
        $this->assertFalse(PlanAccess::isPlatformManaged($paidGroup));
    }

    public function test_charges_for_a_free_group_are_credited_to_the_platform_account(): void
    {
        $group = Group::factory()->create();
        $plan = $this->plan([
            'slug' => 'platform_managed_free',
            'price' => 0,
            'modules' => ['core', 'platform_managed', 'platform_charges'],
        ]);
        $this->subscription($group, $plan);

        $rules = PaymentChargeSettings::defaults();
        $rules[PaymentChargeSettings::ALL_PAYMENTS] = [
            ...$rules[PaymentChargeSettings::ALL_PAYMENTS],
            'enabled' => true,
            'rate_bps' => 150,
        ];

        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => PaymentChargeSettings::KEY],
            ['value' => PaymentChargeSettings::normalize($rules)]
        );

        $charges = app(PaymentChargeService::class);
        $quote = $charges->quote($group, 10_000, [PaymentChargeSettings::ALL_PAYMENTS]);
        $lines = $charges->incomeLines($quote, $group);

        $this->assertSame(150, $quote['total_fee']);
        $this->assertSame([LedgerService::PLATFORM_CHARGE_INCOME => 150], $lines);
    }

    public function test_a_plan_without_the_wallet_module_blocks_the_wallet_portal(): void
    {
        [$group, $user] = $this->groupWithMember();
        $plan = $this->plan(['modules' => ['core', 'savings', 'mgr', 'loans']]);
        $this->subscription($group, $plan);

        $this->actingAs($user)->get(route('portal.wallet.show'))->assertForbidden();

        $plan->update(['modules' => ['core', 'wallet']]);

        $this->actingAs($user)->get(route('portal.wallet.show'))->assertOk();
    }

    /**
     * @return array{0: Group, 1: User}
     */
    private function groupWithMember(): array
    {
        $group = Group::factory()->create();

        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'member'],
            [
                'name' => config('chama.roles.member', 'Member'),
                'permissions' => config('chama.role_permissions.member', []),
                'is_system' => true,
            ]
        );

        $user = User::factory()->create();

        Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
                'membership_category' => 'ordinary',
            ]);

        return [$group, $user];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.Str::lower(Str::random(8)),
            'price' => 100_000,
            'interval' => 'monthly',
            'trial_days' => 0,
            'max_groups' => 1,
            'max_members' => 100,
            'modules' => ['core', 'savings', 'mgr', 'loans', 'wallet'],
            'is_active' => true,
        ], $overrides));
    }

    private function subscription(Group $group, Plan $plan): Subscription
    {
        return Subscription::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'auto_renew' => false,
        ]);
    }
}

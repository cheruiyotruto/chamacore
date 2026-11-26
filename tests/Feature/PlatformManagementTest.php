<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\PlatformRevenueService;
use App\Support\GroupResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_set_management_mode_and_branches_inherit_it(): void
    {
        [$root, $admin] = $this->platformFixture();

        $branch = Group::withoutGlobalScopes()->create([
            'name' => 'Mombasa Branch',
            'parent_group_id' => $root->id,
            'is_branch' => true,
            'branch_code' => 'MOM',
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        $this->actingAs($admin)
            ->put(route('platform.groups.management-mode.update', $root), [
                'platform_management_mode' => Group::PLATFORM_MODE_OVERSIGHT,
            ])
            ->assertRedirect();

        $this->assertSame(Group::PLATFORM_MODE_OVERSIGHT, $root->fresh()->platform_management_mode);
        $this->assertTrue($root->fresh()->isPlatformManaged());
        $this->assertSame(Group::PLATFORM_MODE_OVERSIGHT, $branch->fresh()->platformManagementMode());

        $this->actingAs($admin)
            ->put(route('platform.groups.management-mode.update', $branch), [
                'platform_management_mode' => Group::PLATFORM_MODE_SELF,
            ])
            ->assertRedirect();

        $this->assertSame(Group::PLATFORM_MODE_SELF, $branch->fresh()->platformManagementMode());
        $this->assertFalse($branch->fresh()->isPlatformManaged());
    }

    public function test_full_control_lets_super_admin_enter_and_operate_inside_a_marked_group(): void
    {
        [$root, $admin] = $this->platformFixture();

        $this->actingAs($admin)
            ->post(route('platform.groups.enter', $root))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('platform.groups.management-mode.update', $root), [
                'platform_management_mode' => Group::PLATFORM_MODE_FULL,
            ]);

        $this->actingAs($admin)
            ->post(route('platform.groups.enter', $root))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($root->id, (int) session(GroupResolver::FULL_CONTROL_KEY));

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($root->name);

        $this->assertDatabaseHas('members', [
            'group_id' => $root->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('platform.groups.leave'))
            ->assertRedirect(route('platform.dashboard'));

        $this->assertNull(session(GroupResolver::FULL_CONTROL_KEY));
    }

    public function test_marked_group_income_is_reported_in_platform_revenue(): void
    {
        [$root, $admin] = $this->platformFixture();

        $root->update([
            'platform_management_mode' => Group::PLATFORM_MODE_OVERSIGHT,
            'platform_managed_at' => now(),
            'platform_managed_by' => $admin->id,
        ]);

        $ledger = app(LedgerService::class);
        $ledger->bootstrap($root);
        $ledger->post($root, 'Platform-managed charge', [
            ['code' => LedgerService::CASH, 'debit' => 12_500],
            ['code' => LedgerService::TRANSACTION_CHARGE_INCOME, 'credit' => 12_500],
        ]);

        $summary = app(PlatformRevenueService::class)->summary();

        $this->assertSame(12_500, $summary['groupIncome']);
        $this->assertSame(12_500, $summary['totalIncome'] - $summary['subscriptionIncome']);

        $this->actingAs($admin)
            ->get(route('platform.revenue.index'))
            ->assertOk()
            ->assertSee('KES 125.00');
    }

    /**
     * @return array{0:Group,1:User}
     */
    private function platformFixture(): array
    {
        $root = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($root);
        app(LedgerService::class)->bootstrap($root);

        $admin = User::factory()->superAdmin()->create();

        return [$root, $admin];
    }
}

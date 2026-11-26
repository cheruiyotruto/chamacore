<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\PaymentChargeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_admin_can_manage_bills_and_payment_charge_settings(): void
    {
        $group = Group::factory()->create();

        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'group_admin'],
            [
                'name' => 'Group Administrator',
                'permissions' => ['*'],
                'is_system' => true,
            ]
        );

        /** @var User $admin */
        $admin = User::factory()->create();

        /** @var Member $member */
        $member = Member::factory()
            ->forGroup($group)
            ->forUser($admin)
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
            ]);

        $bill = Bill::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'payee_name' => 'Electricity Company',
            'payee_account' => 'ACC-1',
            'amount' => 5_000,
            'status' => Bill::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('bills.index'))
            ->assertOk()
            ->assertSee($bill->bill_no);

        $this->actingAs($admin)
            ->get(route('bills.show', $bill))
            ->assertOk()
            ->assertSee('Record a payment');

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Payment charges');

        $payload = [];
        foreach (PaymentChargeSettings::defaults() as $key => $rule) {
            $payload['rules'][$key] = [
                'enabled' => $key === PaymentChargeSettings::BILL_PAYMENTS ? '1' : '0',
                'mode' => PaymentChargeSettings::MODE_FIXED,
                'rate_bps' => 0,
                'amount' => '25',
                'min_amount' => '0',
                'max_amount' => '0',
                'borne_by' => PaymentChargeSettings::BORNE_BY_PAYER,
                'income_account_code' => '4060',
            ];
        }

        $this->actingAs($admin)
            ->put(route('settings.payment-charges.update'), $payload)
            ->assertRedirect();

        $settings = GroupSetting::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', PaymentChargeSettings::KEY)
            ->firstOrFail();

        $this->assertTrue($settings->value[PaymentChargeSettings::BILL_PAYMENTS]['enabled']);
        $this->assertSame(2_500, $settings->value[PaymentChargeSettings::BILL_PAYMENTS]['amount']);
        $this->assertFalse($settings->value[PaymentChargeSettings::ALL_PAYMENTS]['enabled']);
    }

    public function test_super_admin_can_set_platform_payment_charge_defaults(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->get(route('platform.charges.index'))
            ->assertOk()
            ->assertSee('Platform-wide defaults');

        $payload = [];
        foreach (PaymentChargeSettings::defaults() as $key => $rule) {
            $payload['rules'][$key] = [
                'enabled' => $key === PaymentChargeSettings::BILL_PAYMENTS ? '1' : '0',
                'mode' => PaymentChargeSettings::MODE_PERCENT,
                'rate_bps' => 150,
                'amount' => '0',
                'min_amount' => '0',
                'max_amount' => '0',
                'borne_by' => PaymentChargeSettings::BORNE_BY_PAYER,
                'income_account_code' => '4060',
            ];
        }

        $this->actingAs($admin)
            ->put(route('platform.charges.update'), $payload)
            ->assertRedirect();

        $setting = PlatformSetting::query()
            ->where('key', PaymentChargeSettings::KEY)
            ->firstOrFail();

        $this->assertTrue($setting->value[PaymentChargeSettings::BILL_PAYMENTS]['enabled']);
        $this->assertSame(150, $setting->value[PaymentChargeSettings::BILL_PAYMENTS]['rate_bps']);
    }
}

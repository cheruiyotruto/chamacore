<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\FloatPurchase;
use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\MpesaAgent;
use App\Models\Role;
use App\Models\User;
use App\Services\FloatPurchaseService;
use App\Services\LedgerService;
use App\Services\WalletService;
use App\Support\FloatAccess;
use App\Support\GroupContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FloatPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_buy_float_and_staff_can_fulfil_it(): void
    {
        [$group, $member, $user] = $this->memberWithWallet();
        $service = app(FloatPurchaseService::class);

        $purchase = $service->purchase($group, $member, $user, [
            'amount' => 10_000,
            'agent_number' => '123456',
            'store_number' => '8899001',
            'phone' => '0712 345 678',
            'idempotency_key' => 'test-purchase-1',
        ]);

        $this->assertSame(FloatPurchase::STATUS_PENDING, $purchase->status);
        $this->assertSame('254712345678', $purchase->phone);
        $this->assertSame('123456', $purchase->agent_number);
        $this->assertSame('8899001', $purchase->store_number);
        $this->assertSame(10_000, $purchase->total_amount);
        $this->assertSame(90_000, app(WalletService::class)->ensureWallet($member)->available_balance);
        $this->assertSame(10_000, app(LedgerService::class)->accountBalance($group, LedgerService::PAYOUT_CLEARING));

        $service->complete($purchase, $user, 'PROV-123');

        $this->assertSame(FloatPurchase::STATUS_COMPLETED, $purchase->refresh()->status);
        $this->assertSame('PROV-123', $purchase->provider_reference);
        $this->assertSame(0, app(LedgerService::class)->accountBalance($group, LedgerService::PAYOUT_CLEARING));
        $this->assertSame(90_000, app(WalletService::class)->ensureWallet($member)->available_balance);
    }

    public function test_a_failed_purchase_refunds_the_wallet(): void
    {
        [$group, $member, $user] = $this->memberWithWallet();
        $service = app(FloatPurchaseService::class);

        $purchase = $service->purchase($group, $member, $user, [
            'amount' => 5_000,
            'agent_number' => '223344',
            'store_number' => '7788990',
            'phone' => '0722000000',
            'idempotency_key' => 'test-purchase-2',
        ]);

        $service->fail($purchase, $user, 'Invalid M-Pesa number');

        $this->assertSame(FloatPurchase::STATUS_FAILED, $purchase->refresh()->status);
        $this->assertSame('Invalid M-Pesa number', $purchase->failure_reason);
        $this->assertSame(100_000, app(WalletService::class)->ensureWallet($member)->available_balance);
        $this->assertSame(0, app(LedgerService::class)->accountBalance($group, LedgerService::PAYOUT_CLEARING));
        $this->assertDatabaseHas('wallet_transactions', [
            'member_id' => $member->id,
            'type' => 'float_purchase_refund',
            'amount' => 5_000,
        ]);
    }

    public function test_a_group_can_disable_member_float_purchases(): void
    {
        [$group, $member, $user] = $this->memberWithWallet();

        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => FloatAccess::SETTING_KEY],
            ['value' => false]
        );

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not enabled');

        app(FloatPurchaseService::class)->purchase($group, $member, $user, [
            'amount' => 1_000,
            'agent_number' => '123456',
            'store_number' => '8899001',
            'phone' => '0700000000',
        ]);
    }

    public function test_a_configured_provider_completes_the_purchase_and_duplicate_callbacks_are_safe(): void
    {
        [$group, $member, $user] = $this->memberWithWallet();

        config()->set('services.float.driver', 'http');
        config()->set('services.float.endpoint', 'https://float.test/recharge');
        config()->set('services.float.token', 'test-token');

        Http::fake([
            'float.test/*' => Http::response([
                'status' => 'completed',
                'transaction_id' => 'PROV-ABC',
            ]),
        ]);

        $service = app(FloatPurchaseService::class);

        $purchase = $service->purchase($group, $member, $user, [
            'amount' => 2_000,
            'agent_number' => '556677',
            'store_number' => '9900112',
            'phone' => '0799123456',
            'idempotency_key' => 'test-purchase-3',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['agent_number'] ?? null) === '556677'
                && ($data['store_number'] ?? null) === '9900112'
                && ($data['phone'] ?? null) === '254799123456';
        });

        $this->assertSame(FloatPurchase::STATUS_COMPLETED, $purchase->refresh()->status);
        $this->assertSame('PROV-ABC', $purchase->provider_reference);
        $this->assertSame(98_000, app(WalletService::class)->ensureWallet($member)->available_balance);

        $service->handleCallback([
            'reference' => $purchase->reference,
            'status' => 'failed',
        ]);

        $this->assertSame(FloatPurchase::STATUS_COMPLETED, $purchase->refresh()->status);
        $this->assertSame(0, app(LedgerService::class)->accountBalance($group, LedgerService::PAYOUT_CLEARING));
    }

    public function test_a_registered_agent_and_store_can_be_selected_for_a_purchase(): void
    {
        [$group, $member, $user] = $this->memberWithWallet();

        $agent = MpesaAgent::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'name' => 'Main branch agent',
            'agent_number' => '445566',
            'store_number' => '1122334',
            'phone' => '0712345000',
            'status' => MpesaAgent::STATUS_ACTIVE,
        ]);

        $purchase = app(FloatPurchaseService::class)->purchase($group, $member, $user, [
            'amount' => 3_000,
            'agent_id' => $agent->id,
            'idempotency_key' => 'test-purchase-4',
        ]);

        $this->assertSame($agent->id, $purchase->agent_id);
        $this->assertSame('445566', $purchase->agent_number);
        $this->assertSame('1122334', $purchase->store_number);
        $this->assertSame('Main branch agent', $purchase->agent_name);
        $this->assertSame('254712345000', $purchase->phone);
    }

    public function test_manual_purchases_require_agent_and_store_numbers(): void
    {
        [$group, $member, $user] = $this->memberWithWallet();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('agent number');

        app(FloatPurchaseService::class)->purchase($group, $member, $user, [
            'amount' => 1_000,
            'phone' => '0700000000',
        ]);
    }

    public function test_admin_can_open_the_agent_registry_page(): void
    {
        $group = Group::factory()->create();

        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'group_admin'],
            ['name' => 'Group Administrator', 'permissions' => ['*'], 'is_system' => true]
        );

        /** @var User $user */
        $user = User::factory()->create();

        Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
                'membership_category' => 'ordinary',
            ]);

        MpesaAgent::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Main branch agent',
            'agent_number' => '123456',
            'store_number' => '8899001',
            'phone' => '0712345678',
            'status' => MpesaAgent::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('mpesa-agents.index'))
            ->assertOk()
            ->assertSee('8899001');
    }

    /**
     * @return array{0: Group, 1: Member, 2: User}
     */
    private function memberWithWallet(): array
    {
        $group = Group::factory()->create();
        app(LedgerService::class)->bootstrap($group);

        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'member'],
            [
                'name' => config('chama.roles.member', 'Member'),
                'permissions' => config('chama.role_permissions.member', []),
                'is_system' => true,
            ]
        );

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Member $member */
        $member = Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create(['role_id' => $role->id, 'status' => 'active']);

        app(WalletService::class)->credit(
            $member,
            100_000,
            'wallet_topup',
            LedgerService::CASH,
            'TEST-FLOAT-TOPUP'
        );

        $this->actingAs($user);
        app(GroupContext::class)->setMembership($member);

        return [$group, $member, $user];
    }
}

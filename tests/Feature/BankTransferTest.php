<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Bill;
use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\PaymentDestination;
use App\Models\Payout;
use App\Models\Role;
use App\Models\User;
use App\Services\BillService;
use App\Services\Gateways\BankTransferGateway;
use App\Services\Gateways\MpesaPayoutGateway;
use App\Services\LedgerService;
use App\Services\PayoutService;
use App\Services\WalletService;
use App\Support\GroupContext;
use App\Support\PayoutAccess;
use App\Support\PaymentChargeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BankTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_staff_can_reserve_and_settle_a_transfer_with_configurable_charges(): void
    {
        [$group, $admin, $user] = $this->groupWithAdmin();
        $destination = $this->destination($group);
        $this->mockBankGateway('BANK-SETTLE');
        $this->savePayoutRules($group, 1_000, PaymentChargeSettings::BORNE_BY_PAYER);

        $service = app(PayoutService::class);
        $payout = $service->initiate(
            $service->create($group, [
                'destination_id' => $destination->id,
                'amount' => 10_000,
                'source' => Payout::SOURCE_BILLS,
            ], $user),
            $user
        );

        $this->assertSame(Payout::STATUS_PROCESSING, $payout->status);
        $this->assertSame(11_000, $payout->total_amount);
        $this->assertSame(10_000, $payout->net_amount);
        $this->assertSame(1_000, $payout->charge_amount);

        $ledger = app(LedgerService::class);
        $this->assertSame(-11_000, $ledger->accountBalance($group, LedgerService::BILL_PAYABLE));
        $this->assertSame(11_000, $ledger->accountBalance($group, LedgerService::PAYOUT_CLEARING));

        $result = $service->handleCallback('bank', [
            'reference' => 'BANK-SETTLE',
            'status' => 'completed',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame(Payout::STATUS_SUCCEEDED, $payout->refresh()->status);
        $this->assertSame(0, $ledger->accountBalance($group, LedgerService::PAYOUT_CLEARING));
        // Crediting the bank asset reduces its debit-normal balance.
        $this->assertSame(-10_000, $ledger->accountBalance($group, LedgerService::BANK));
        $this->assertSame(1_000, $ledger->accountBalance($group, LedgerService::TRANSACTION_CHARGE_INCOME));

        foreach (Payout::withoutGlobalScopes()->whereKey($payout->id)->get() as $item) {
            $this->assertNotNull($item->journal_entry_id);
        }
    }

    public function test_a_failed_transfer_releases_the_reservation(): void
    {
        [$group, $admin, $user] = $this->groupWithAdmin();
        $destination = $this->destination($group);
        $this->mockBankGateway('BANK-FAIL');

        $service = app(PayoutService::class);
        $payout = $service->initiate(
            $service->create($group, [
                'destination_id' => $destination->id,
                'amount' => 10_000,
            ], $user),
            $user
        );

        $ledger = app(LedgerService::class);
        $this->assertSame(10_000, $ledger->accountBalance($group, LedgerService::PAYOUT_CLEARING));

        $result = $service->handleCallback('bank', [
            'reference' => 'BANK-FAIL',
            'status' => 'failed',
            'reason' => 'Account number invalid',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(Payout::STATUS_FAILED, $payout->refresh()->status);
        $this->assertSame('Account number invalid', $payout->failure_reason);
        $this->assertSame(0, $ledger->accountBalance($group, LedgerService::PAYOUT_CLEARING));
        $this->assertSame(0, $ledger->accountBalance($group, LedgerService::BILL_PAYABLE));
    }

    public function test_only_collected_bill_funds_can_be_transferred(): void
    {
        [$group, $admin, $user] = $this->groupWithAdmin();
        $destination = $this->destination($group);
        $this->mockBankGateway('BILL-PAYOUT');

        $bill = app(BillService::class)->create($group, [
            'member_id' => $admin->id,
            'payee_name' => 'County Water',
            'amount' => 10_000,
            'status' => Bill::STATUS_PENDING,
        ], $user);

        $payment = app(BillService::class)->payAtCounter(
            $group, $bill, $admin, 10_000, 'cash', null, $user
        );

        $this->assertSame(10_000, $payment->settlement_amount);
        $this->assertSame(10_000, $bill->refresh()->settlementBalance());

        $service = app(PayoutService::class);
        $service->create($group, [
            'destination_id' => $destination->id,
            'amount' => 10_000,
            'bill_id' => $bill->id,
        ], $user);

        try {
            $service->create($group, [
                'destination_id' => $destination->id,
                'amount' => 100,
                'bill_id' => $bill->id,
            ], $user);
            $this->fail('A second transfer should not exceed the collected settlement balance.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not have enough collected funds', $e->getMessage());
        }
    }

    public function test_member_transfer_requires_both_the_group_switch_and_member_opt_in(): void
    {
        [$group, $admin, $user] = $this->groupWithAdmin();
        [$memberGroup, $member] = $this->groupWithMember($group);
        $destination = $this->destination($group);
        $this->mockBankGateway('MEMBER-PAYOUT');

        $wallets = app(WalletService::class);
        $wallets->credit($member, 50_000, 'test_credit', LedgerService::CASH, 'FUND-MEMBER');

        $this->actingAs($member->user);
        app(GroupContext::class)->setMembership($member);

        $service = app(PayoutService::class);

        try {
            $service->create($group, [
                'destination_id' => $destination->id,
                'amount' => 10_000,
                'source' => Payout::SOURCE_WALLET,
                'member_id' => $member->id,
            ], $member->user);
            $this->fail('A member without access must not be able to transfer.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }

        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => PayoutAccess::KEY],
            ['value' => ['members_enabled' => true]]
        );
        $member->update(['can_bank_transfer' => true]);

        $payout = $service->initiate(
            $service->create($group, [
                'destination_id' => $destination->id,
                'amount' => 10_000,
                'source' => Payout::SOURCE_WALLET,
                'member_id' => $member->id,
            ], $member->user),
            $member->user
        );

        $this->assertSame(Payout::STATUS_PROCESSING, $payout->status);
        $this->assertSame(40_000, $member->wallet()->firstOrFail()->balance);

        $service->handleCallback('bank', ['reference' => 'MEMBER-PAYOUT', 'status' => 'rejected']);

        $this->assertSame(Payout::STATUS_FAILED, $payout->refresh()->status);
        $this->assertSame(50_000, $member->wallet()->firstOrFail()->balance);
    }

    public function test_admin_can_open_transfers_for_members_and_opt_in_individuals(): void
    {
        [$group, $admin, $user] = $this->groupWithAdmin();
        [, $member] = $this->groupWithMember($group);

        $this->actingAs($user)
            ->put(route('bank-transfers.access.update'), [
                'members_enabled' => 1,
                'members' => [$member->id => 1],
            ])
            ->assertRedirect();

        $this->assertTrue(PayoutAccess::groupEnabled($group->fresh()));
        $this->assertTrue($member->fresh()->can_bank_transfer);
        $this->assertFalse($admin->fresh()->can_bank_transfer);

        $this->actingAs($user)
            ->get(route('bank-transfers.index'))
            ->assertOk()
            ->assertSee('Member access');
    }

    public function test_mpesa_b2b_callback_settles_a_paybill_payout(): void
    {
        [$group, $admin, $user] = $this->groupWithAdmin();

        $paybill = PaymentDestination::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'County Water PayBill',
            'type' => PaymentDestination::TYPE_PAYBILL,
            'paybill_number' => '123456',
            'account_name' => 'WATER-001',
            'is_active' => true,
        ]);

        $gateway = Mockery::mock(MpesaPayoutGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('initiate')->andReturn([
            'reference' => 'MPESA-B2B-1',
            'response' => ['OriginatorConversationID' => 'MPESA-B2B-1'],
        ]);
        $this->app->instance(MpesaPayoutGateway::class, $gateway);

        $service = app(PayoutService::class);
        $payout = $service->initiate(
            $service->create($group, [
                'destination_id' => $paybill->id,
                'amount' => 10_000,
            ], $user),
            $user
        );

        $this->assertSame('mpesa', $payout->provider);
        $this->assertSame('MPESA-B2B-1', $payout->provider_reference);

        $result = $service->handleCallback('mpesa', [
            'Result' => [
                'OriginatorConversationID' => 'MPESA-B2B-1',
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted',
                'TransactionID' => 'QB1XABC123',
            ],
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame(Payout::STATUS_SUCCEEDED, $payout->refresh()->status);
        $this->assertSame('QB1XABC123', $payout->provider_reference);
        $this->assertSame(0, app(LedgerService::class)->accountBalance($group, LedgerService::PAYOUT_CLEARING));
    }

    /**
     * @return array{0: Group, 1: Member, 2: User}
     */
    private function groupWithAdmin(): array
    {
        $group = Group::factory()->create();

        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'group_admin'],
            ['name' => 'Group Administrator', 'permissions' => ['*'], 'is_system' => true]
        );

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Member $member */
        $member = Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create(['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user);
        app(GroupContext::class)->setMembership($member);

        return [$group, $member, $user];
    }

    /**
     * @return array{0: Group, 1: Member}
     */
    private function groupWithMember(Group $group): array
    {
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

        return [$group, $member];
    }

    private function destination(Group $group): PaymentDestination
    {
        return PaymentDestination::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'County Water Bank Account',
            'type' => PaymentDestination::TYPE_BANK,
            'bank_name' => 'Test Bank',
            'account_name' => 'County Water',
            'account_number' => '1234567890',
            'is_active' => true,
        ]);
    }

    private function mockBankGateway(string $reference): void
    {
        $gateway = Mockery::mock(BankTransferGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('initiate')->andReturn([
            'reference' => $reference,
            'response' => ['id' => $reference],
        ]);
        $this->app->instance(BankTransferGateway::class, $gateway);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     */
    private function savePayoutRules(Group $group, int $amount, string $borneBy): void
    {
        $rules = PaymentChargeSettings::defaults();
        $rules[PaymentChargeSettings::PAYOUTS] = [
            ...$rules[PaymentChargeSettings::PAYOUTS],
            'enabled' => true,
            'mode' => PaymentChargeSettings::MODE_FIXED,
            'amount' => $amount,
            'borne_by' => $borneBy,
        ];

        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => PaymentChargeSettings::KEY],
            ['value' => PaymentChargeSettings::normalize($rules)]
        );
    }
}

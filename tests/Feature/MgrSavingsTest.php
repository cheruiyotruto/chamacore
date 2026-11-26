<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundCycle;
use App\Models\MerryGoRoundParticipant;
use App\Models\PaymentIntent;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TellerEntry;
use App\Models\User;
use App\Services\Gateways\MpesaGateway;
use App\Services\LedgerService;
use App\Services\MerryGoRoundService;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\GroupContext;
use App\Support\MgrSavingsSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MgrSavingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_the_mgr_savings_settings(): void
    {
        $group = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($group);

        $role = Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'group_admin')
            ->firstOrFail();
        $adminUser = User::factory()->create();
        Member::factory()->forGroup($group)->forUser($adminUser)->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->actingAs($adminUser)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.mgr-savings.update'), [
                'enabled' => '1',
                'mode' => MgrSavingsSettings::MODE_ADD,
                'basis' => MgrSavingsSettings::BASIS_PERCENT,
                'amount' => '250.50',
                'rate_bps' => 1_500,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'MGR savings transfer settings saved.');

        $stored = GroupSetting::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', MgrSavingsSettings::SETTING_KEY)
            ->firstOrFail()
            ->value;

        $this->assertTrue($stored['enabled']);
        $this->assertSame(MgrSavingsSettings::MODE_ADD, $stored['mode']);
        $this->assertSame(MgrSavingsSettings::BASIS_PERCENT, $stored['basis']);
        $this->assertSame(25_050, $stored['amount']);
        $this->assertSame(1_500, $stored['rate_bps']);
    }

    public function test_disabled_setting_is_a_no_op_even_when_an_amount_is_configured(): void
    {
        $fixture = $this->fixture();

        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $fixture['group']->id, 'key' => MgrSavingsSettings::SETTING_KEY],
            ['value' => array_merge(MgrSavingsSettings::defaults(), [
                'enabled' => false,
                'amount' => 10_000,
            ])]
        );

        $this->payFromWallet($fixture['contribution']);

        $this->assertSame('paid', $fixture['contribution']->fresh()->status);
        $this->assertSame(50_000, $fixture['contribution']->fresh()->settlement_amount);
        $this->assertNull($fixture['contribution']->fresh()->savings_transaction_id);
        $this->assertSame(0, SavingsTransaction::withoutGlobalScopes()->count());
        $this->assertSame(50_000, $this->mgrBalance($fixture['group']));
        $this->assertSame(0, $this->savingsBalance($fixture['group']));
        $this->assertSame(50_000, $fixture['payer']->fresh()->wallet->available_balance);
    }

    public function test_fixed_split_moves_the_configured_portion_into_individual_savings(): void
    {
        $fixture = $this->fixture();

        $this->enableMgrSavings($fixture['group'], [
            'mode' => MgrSavingsSettings::MODE_SPLIT,
            'basis' => MgrSavingsSettings::BASIS_FIXED,
            'amount' => 10_000,
        ]);

        $this->payFromWallet($fixture['contribution']);

        $contribution = $fixture['contribution']->fresh();
        $savings = SavingsTransaction::withoutGlobalScopes()->firstOrFail();
        $account = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('member_id', $fixture['payer']->id)
            ->firstOrFail();

        $this->assertSame('paid', $contribution->status);
        $this->assertSame(40_000, $contribution->settlement_amount);
        $this->assertSame($savings->id, (int) $contribution->savings_transaction_id);
        $this->assertSame(10_000, $savings->amount);
        $this->assertSame('transfer_in', $savings->type);
        $this->assertSame('mgr-auto-savings-'.$contribution->id, $savings->idempotency_key);
        $this->assertSame(10_000, $account->balance);
        $this->assertSame(40_000, $this->mgrBalance($fixture['group']));
        $this->assertSame(10_000, $this->savingsBalance($fixture['group']));
        $this->assertSame(50_000, $fixture['payer']->fresh()->wallet->available_balance);
    }

    public function test_add_on_mode_collects_savings_on_top_of_the_full_contribution(): void
    {
        $fixture = $this->fixture();

        $this->enableMgrSavings($fixture['group'], [
            'mode' => MgrSavingsSettings::MODE_ADD,
            'basis' => MgrSavingsSettings::BASIS_FIXED,
            'amount' => 10_000,
        ]);

        $totals = app(MerryGoRoundService::class)->portalAmount($fixture['contribution']);

        $this->assertSame(50_000, $totals['amount']);
        $this->assertSame(10_000, $totals['savings']);
        $this->assertSame(60_000, $totals['total']);

        $this->payFromWallet($fixture['contribution']);

        $contribution = $fixture['contribution']->fresh();

        $this->assertSame(50_000, $contribution->settlement_amount);
        $this->assertSame(10_000, SavingsTransaction::withoutGlobalScopes()->firstOrFail()->amount);
        $this->assertSame(50_000, $this->mgrBalance($fixture['group']));
        $this->assertSame(10_000, $this->savingsBalance($fixture['group']));
        $this->assertSame(40_000, $fixture['payer']->fresh()->wallet->available_balance);
    }

    public function test_percentage_basis_uses_basis_points_of_each_contribution(): void
    {
        $fixture = $this->fixture();

        $this->enableMgrSavings($fixture['group'], [
            'mode' => MgrSavingsSettings::MODE_SPLIT,
            'basis' => MgrSavingsSettings::BASIS_PERCENT,
            'rate_bps' => 2_000,
        ]);

        $this->payFromWallet($fixture['contribution']);

        $contribution = $fixture['contribution']->fresh();

        $this->assertSame(40_000, $contribution->settlement_amount);
        $this->assertSame(10_000, SavingsTransaction::withoutGlobalScopes()->firstOrFail()->amount);
        $this->assertSame(40_000, $this->mgrBalance($fixture['group']));
        $this->assertSame(10_000, $this->savingsBalance($fixture['group']));
    }

    public function test_the_rule_applies_to_every_contribution_in_the_cycle(): void
    {
        $fixture = $this->fixture();
        $second = MerryGoRoundContribution::withoutGlobalScopes()->create([
            'group_id' => $fixture['group']->id,
            'merry_go_round_cycle_id' => $fixture['contribution']->merry_go_round_cycle_id,
            'participant_id' => $fixture['contribution']->participant_id,
            'member_id' => $fixture['payer']->id,
            'amount' => 50_000,
            'due_date' => now()->addDays(35)->toDateString(),
            'status' => 'pending',
        ]);

        $this->enableMgrSavings($fixture['group'], [
            'mode' => MgrSavingsSettings::MODE_SPLIT,
            'basis' => MgrSavingsSettings::BASIS_FIXED,
            'amount' => 10_000,
        ]);

        $this->payFromWallet($fixture['contribution']);
        $this->payFromWallet($second);

        $this->assertSame(2, SavingsTransaction::withoutGlobalScopes()->count());
        $this->assertSame(80_000, $this->mgrBalance($fixture['group']));
        $this->assertSame(20_000, $this->savingsBalance($fixture['group']));
        $this->assertSame(80_000, $fixture['participant']->fresh()->total_contributed);
        $this->assertSame(0, $fixture['payer']->fresh()->wallet->available_balance);
    }

    public function test_teller_posted_mgr_contribution_uses_the_add_on_amount_on_the_receipt(): void
    {
        $fixture = $this->fixture();

        $this->enableMgrSavings($fixture['group'], [
            'mode' => MgrSavingsSettings::MODE_ADD,
            'basis' => MgrSavingsSettings::BASIS_FIXED,
            'amount' => 10_000,
        ]);

        $tellerRole = Role::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('key', 'teller')
            ->firstOrFail();
        $tellerUser = User::factory()->create();
        Member::factory()->forGroup($fixture['group'])->forUser($tellerUser)->create([
            'role_id' => $tellerRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($tellerUser)->post(route('teller.sessions.open'), [
            'opening_float' => '1000',
        ])->assertRedirect();

        $this->actingAs($tellerUser)->post(route('teller.entries.store'), [
            'member_id' => $fixture['payer']->id,
            'category' => 'mgr_contribution',
            'contribution_id' => $fixture['contribution']->id,
            'amount' => '500',
            'method' => 'cash',
            'reference' => 'TELLER-MGR-1',
            'idempotency_key' => 'teller-mgr-savings-1',
        ])->assertRedirect();

        $entry = TellerEntry::withoutGlobalScopes()->firstOrFail();
        $contribution = $fixture['contribution']->fresh();

        $this->assertSame('mgr_contribution', $entry->category);
        $this->assertSame(60_000, $entry->amount);
        $this->assertSame('paid', $contribution->status);
        $this->assertSame(50_000, $contribution->settlement_amount);
        $this->assertSame(10_000, SavingsTransaction::withoutGlobalScopes()->firstOrFail()->amount);
        $this->assertSame(50_000, $this->mgrBalance($fixture['group']));
        $this->assertSame(10_000, $this->savingsBalance($fixture['group']));
    }

    public function test_duplicate_mpesa_callback_does_not_post_savings_twice(): void
    {
        $fixture = $this->fixture();

        $this->enableMgrSavings($fixture['group'], [
            'mode' => MgrSavingsSettings::MODE_SPLIT,
            'basis' => MgrSavingsSettings::BASIS_FIXED,
            'amount' => 10_000,
        ]);

        $gateway = \Mockery::mock(MpesaGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('initiate')->once()->andReturn([
            'reference' => 'MGR-SAVINGS-CHECKOUT',
            'response' => ['CheckoutRequestID' => 'MGR-SAVINGS-CHECKOUT'],
        ]);
        $this->app->instance(MpesaGateway::class, $gateway);

        app(PaymentService::class)->initiate($fixture['payer'], [
            'purpose' => 'mgr_contribution',
            'amount' => 50_000,
            'phone' => '254712345678',
            'payload' => [
                'contribution_id' => $fixture['contribution']->id,
                'penalty_amount' => 0,
                'manual_charge' => null,
            ],
        ], 'mgr-savings-'.$fixture['contribution']->id);

        $callback = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'MGR-SAVINGS-CHECKOUT',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 50_000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MGR-SAVINGS-RECEIPT'],
                        ],
                    ],
                ],
            ],
        ];

        $payments = app(PaymentService::class);

        $this->assertSame('succeeded', $payments->handleMpesaCallback($callback)['status']);
        $this->assertSame('duplicate', $payments->handleMpesaCallback($callback)['status']);

        $contribution = $fixture['contribution']->fresh();

        $this->assertSame('paid', $contribution->status);
        $this->assertSame(40_000, $contribution->settlement_amount);
        $this->assertSame(1, SavingsTransaction::withoutGlobalScopes()->count());
        $this->assertSame(40_000, $this->mgrBalance($fixture['group']));
        $this->assertSame(10_000, $this->savingsBalance($fixture['group']));
        $this->assertSame(1, PaymentIntent::withoutGlobalScopes()->where('purpose', 'mgr_contribution')->count());
    }

    private function enableMgrSavings(Group $group, array $overrides = []): void
    {
        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => MgrSavingsSettings::SETTING_KEY],
            ['value' => array_merge(MgrSavingsSettings::defaults(), [
                'enabled' => true,
                'mode' => MgrSavingsSettings::MODE_SPLIT,
                'basis' => MgrSavingsSettings::BASIS_FIXED,
                'amount' => 10_000,
            ], $overrides)]
        );
    }

    private function payFromWallet(MerryGoRoundContribution $contribution): void
    {
        $this->actingAs($contribution->member->user)
            ->withSession(['active_group_id' => $contribution->group_id])
            ->post(route('portal.mgr.contributions.pay-wallet', $contribution))
            ->assertRedirect();
    }

    private function mgrBalance(Group $group): int
    {
        return app(LedgerService::class)->accountBalance($group, LedgerService::MGR_FUNDS);
    }

    private function savingsBalance(Group $group): int
    {
        return app(LedgerService::class)->accountBalance($group, LedgerService::SAVINGS_LIABILITY);
    }

    /**
     * @return array{
     *     group: Group,
     *     payer: Member,
     *     payerUser: User,
     *     participant: MerryGoRoundParticipant,
     *     contribution: MerryGoRoundContribution
     * }
     */
    private function fixture(int $contributionAmount = 50_000, int $walletAmount = 100_000): array
    {
        $group = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($group);
        app(LedgerService::class)->bootstrap($group);

        $role = Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'member')
            ->firstOrFail();

        $payerUser = User::factory()->create();
        $payer = Member::factory()->forGroup($group)->forUser($payerUser)->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Member savings',
            'code' => 'SAV',
            'type' => SavingsProduct::TYPE_MANDATORY,
            'is_active' => true,
        ]);

        $cycle = MerryGoRoundCycle::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Savings rotation',
            'contribution_amount' => $contributionAmount,
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
            'rotation_type' => 'fixed',
            'status' => 'active',
            'penalty_rate_bps' => 0,
            'penalty_grace_days' => 0,
            'payout_requires_contributions' => true,
            'min_contributions_before_payout' => 1,
            'created_by' => $payerUser->id,
        ]);

        $participant = MerryGoRoundParticipant::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'merry_go_round_cycle_id' => $cycle->id,
            'member_id' => $payer->id,
            'position' => 1,
            'payout_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $contribution = MerryGoRoundContribution::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'merry_go_round_cycle_id' => $cycle->id,
            'participant_id' => $participant->id,
            'member_id' => $payer->id,
            'amount' => $contributionAmount,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => 'pending',
        ]);

        app(WalletService::class)->credit(
            $payer,
            $walletAmount,
            'wallet_topup',
            LedgerService::CASH,
            'TEST-MGR-SAVINGS-TOP-UP'
        );

        app(GroupContext::class)->setMembership($payer->load('role', 'group'));

        return [
            'group' => $group,
            'payer' => $payer,
            'payerUser' => $payerUser,
            'participant' => $participant,
            'contribution' => $contribution,
        ];
    }
}

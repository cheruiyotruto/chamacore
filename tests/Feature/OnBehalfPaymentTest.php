<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundCycle;
use App\Models\MerryGoRoundParticipant;
use App\Models\OnBehalfPayment;
use App\Models\PaymentIntent;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Gateways\MpesaGateway;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\GroupContext;
use App\Support\OnBehalfPayment as OnBehalfPayments;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnBehalfPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_is_hidden_and_blocked_until_a_group_admin_enables_it(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertDontSee('Pay for a member');

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->get(route('portal.on-behalf.index'))
            ->assertForbidden();
    }

    public function test_group_admin_can_save_the_on_behalf_payment_toggle(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['adminUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->put(route('settings.values.update'), ['allow_member_on_behalf_payments' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $setting = GroupSetting::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('key', OnBehalfPayments::SETTING_KEY)
            ->firstOrFail();

        $this->assertTrue($setting->value);
        $this->assertTrue(OnBehalfPayments::enabled($fixture['group']->fresh()));
    }

    public function test_enabled_feature_shows_the_page_and_navigation_link(): void
    {
        $fixture = $this->fixture();
        $this->enable($fixture['group']);

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Pay for a member');

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->get(route('portal.on-behalf.index'))
            ->assertOk()
            ->assertSee('Pay for a member')
            ->assertSee($fixture['beneficiary']->full_name)
            ->assertSee(route('portal.on-behalf.savings'), false)
            ->assertSee(route('portal.on-behalf.mgr'), false);
    }

    public function test_payer_can_make_a_wallet_funded_savings_deposit_for_another_member(): void
    {
        $fixture = $this->fixture();
        $this->enable($fixture['group']);

        $walletBefore = $this->walletBalance($fixture['payer']);

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.on-behalf.savings'), [
                'beneficiary_id' => $fixture['beneficiary']->id,
                'savings_account_id' => $fixture['account']->id,
                'amount' => '10.00',
                'method' => 'wallet',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1_000, $fixture['account']->fresh()->balance);
        $this->assertSame($walletBefore - 1_000, $this->walletBalance($fixture['payer']));

        $record = OnBehalfPayment::withoutGlobalScopes()->where('type', 'savings')->firstOrFail();
        $this->assertSame($fixture['payer']->id, $record->payer_member_id);
        $this->assertSame($fixture['beneficiary']->id, $record->member_id);
        $this->assertSame(1_000, $record->amount);
        $this->assertSame('wallet', $record->method);
        $this->assertSame('completed', $record->status);
    }

    public function test_payer_can_repay_another_members_loan_from_their_wallet(): void
    {
        $fixture = $this->fixture();
        $this->enable($fixture['group']);

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.on-behalf.loan'), [
                'beneficiary_id' => $fixture['beneficiary']->id,
                'loan_id' => $fixture['loan']->id,
                'amount' => '5.00',
                'method' => 'wallet',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(99_500, $fixture['loan']->fresh()->outstanding_principal);
        $record = OnBehalfPayment::withoutGlobalScopes()->where('type', 'loan')->firstOrFail();
        $this->assertSame($fixture['payer']->id, $record->payer_member_id);
        $this->assertSame($fixture['beneficiary']->id, $record->member_id);
        $this->assertSame(500, $record->amount);
    }

    public function test_payer_can_pay_another_members_bill_from_their_wallet(): void
    {
        $fixture = $this->fixture();
        $this->enable($fixture['group']);

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.on-behalf.bill'), [
                'beneficiary_id' => $fixture['beneficiary']->id,
                'bill_id' => $fixture['bill']->id,
                'amount' => '20.00',
                'method' => 'wallet',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2_000, $fixture['bill']->fresh()->amount_paid);
        $record = OnBehalfPayment::withoutGlobalScopes()->where('type', 'bill')->firstOrFail();
        $this->assertSame($fixture['payer']->id, $record->payer_member_id);
        $this->assertSame($fixture['beneficiary']->id, $record->member_id);
        $this->assertSame(2_000, $record->amount);
    }

    public function test_payer_can_pay_another_members_mgr_contribution_from_their_wallet(): void
    {
        $fixture = $this->fixture();
        $this->enable($fixture['group']);

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.on-behalf.mgr'), [
                'beneficiary_id' => $fixture['beneficiary']->id,
                'contribution_id' => $fixture['contribution']->id,
                'method' => 'wallet',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $fixture['contribution']->fresh()->status);
        $record = OnBehalfPayment::withoutGlobalScopes()->where('type', 'mgr')->firstOrFail();
        $this->assertSame($fixture['payer']->id, $record->payer_member_id);
        $this->assertSame($fixture['beneficiary']->id, $record->member_id);
        $this->assertSame(5_000, $record->amount);
    }

    public function test_payer_can_credit_another_members_wallet_from_their_own_wallet(): void
    {
        $fixture = $this->fixture();
        $this->enable($fixture['group']);

        $before = $this->walletBalance($fixture['beneficiary']);

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.on-behalf.wallet'), [
                'beneficiary_id' => $fixture['beneficiary']->id,
                'amount' => '12.00',
                'method' => 'wallet',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1_200, $this->walletBalance($fixture['beneficiary']));
        $record = OnBehalfPayment::withoutGlobalScopes()->where('type', 'wallet')->firstOrFail();
        $this->assertSame($fixture['payer']->id, $record->payer_member_id);
        $this->assertSame($fixture['beneficiary']->id, $record->member_id);
        $this->assertSame(1_200, $record->amount);
    }

    public function test_mpesa_intent_records_both_members_and_callback_completes_the_audit(): void
    {
        $fixture = $this->fixture();
        $this->enable($fixture['group']);

        $gateway = \Mockery::mock(MpesaGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('initiate')->once()->andReturn([
            'reference' => 'OB-CHECKOUT',
            'response' => ['CheckoutRequestID' => 'OB-CHECKOUT'],
        ]);
        $this->app->instance(MpesaGateway::class, $gateway);

        $before = $this->walletBalance($fixture['beneficiary']);

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.on-behalf.wallet'), [
                'beneficiary_id' => $fixture['beneficiary']->id,
                'amount' => '15.00',
                'method' => 'mpesa',
                'phone' => '254712345678',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $intent = PaymentIntent::withoutGlobalScopes()->where('purpose', 'wallet_topup')->firstOrFail();
        $this->assertSame($fixture['beneficiary']->id, $intent->member_id);
        $this->assertSame($fixture['payer']->id, $intent->payer_member_id);

        $record = OnBehalfPayment::withoutGlobalScopes()->where('type', 'wallet')->firstOrFail();
        $this->assertSame($intent->id, $record->payment_intent_id);
        $this->assertSame('mpesa', $record->method);
        $this->assertSame('pending', $record->status);

        $callback = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'OB-CHECKOUT',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => number_format($intent->amount / 100, 2, '.', '')],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'OB-RECEIPT'],
                        ],
                    ],
                ],
            ],
        ];

        $result = app(PaymentService::class)->handleMpesaCallback($callback);
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('succeeded', $record->fresh()->status);
        $this->assertSame($before + 1_500, $this->walletBalance($fixture['beneficiary']));
    }

    /**
     * @return array{
     *     group:Group,
     *     payer:Member,
     *     payerUser:User,
     *     beneficiary:Member,
     *     adminUser:User,
     *     account:SavingsAccount,
     *     loan:Loan,
     *     bill:Bill,
     *     contribution:MerryGoRoundContribution
     * }
     */
    private function fixture(): array
    {
        $group = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($group);
        app(LedgerService::class)->bootstrap($group);

        $memberRole = Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'member')
            ->firstOrFail();
        $adminRole = Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'group_admin')
            ->firstOrFail();

        $payerUser = User::factory()->create();
        $beneficiaryUser = User::factory()->create();
        $adminUser = User::factory()->create();

        $payer = Member::factory()->forGroup($group)->forUser($payerUser)->create([
            'role_id' => $memberRole->id,
            'status' => 'active',
        ]);
        $beneficiary = Member::factory()->forGroup($group)->forUser($beneficiaryUser)->create([
            'role_id' => $memberRole->id,
            'status' => 'active',
        ]);
        Member::factory()->forGroup($group)->forUser($adminUser)->create([
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);

        app(GroupContext::class)->setMembership($payer->load('role', 'group'));

        $product = SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Ordinary savings',
            'code' => 'ORD',
            'type' => SavingsProduct::TYPE_VOLUNTARY,
            'interest_rate_bps' => 0,
            'interest_period' => 'annual',
            'min_balance' => 0,
            'allows_withdrawal' => true,
            'qualifies_for_loan' => true,
            'is_active' => true,
        ]);

        $account = SavingsAccount::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $beneficiary->id,
            'savings_product_id' => $product->id,
            'account_no' => 'SA-OB-'.$beneficiary->id,
            'balance' => 0,
            'available_balance' => 0,
            'pledged_amount' => 0,
            'status' => 'active',
            'opened_at' => now(),
        ]);

        $loanProduct = LoanProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Test loan',
            'code' => 'LOB-'.$beneficiary->id,
            'interest_method' => 'reducing_annuity',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'repayment_frequency' => 'monthly',
            'duration_min' => 1,
            'duration_max' => 12,
            'duration_unit' => 'months',
            'min_amount' => 0,
            'max_amount' => 5_000_000,
        ]);

        $loan = Loan::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $beneficiary->id,
            'loan_product_id' => $loanProduct->id,
            'status' => Loan::STATUS_ACTIVE,
            'loan_no' => 'LN-OB-'.$beneficiary->id,
            'requested_amount' => 100_000,
            'principal' => 100_000,
            'total_repayable' => 110_000,
            'outstanding_principal' => 100_000,
            'outstanding_interest' => 0,
            'outstanding_fees' => 0,
            'outstanding_penalties' => 0,
            'duration' => 12,
            'duration_unit' => 'months',
            'repayment_frequency' => 'monthly',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'interest_method' => 'reducing_annuity',
            'accrual_mode' => 'effective',
            'day_count' => 'actual_365',
        ]);

        LoanSchedule::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'loan_id' => $loan->id,
            'installment_no' => 1,
            'due_date' => now()->addDays(30)->toDateString(),
            'principal_due' => 100_000,
            'interest_due' => 0,
            'total_due' => 100_000,
            'status' => 'pending',
        ]);

        $bill = Bill::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $beneficiary->id,
            'payee_name' => 'County water',
            'payee_account' => 'WATER-1',
            'amount' => 5_000,
            'status' => Bill::STATUS_PENDING,
        ]);

        $cycle = MerryGoRoundCycle::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'On-behalf rotation',
            'contribution_amount' => 5_000,
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
            'member_id' => $beneficiary->id,
            'position' => 1,
            'payout_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $contribution = MerryGoRoundContribution::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'merry_go_round_cycle_id' => $cycle->id,
            'participant_id' => $participant->id,
            'member_id' => $beneficiary->id,
            'amount' => 5_000,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => 'pending',
        ]);

        app(WalletService::class)->credit(
            $payer,
            100_000,
            'wallet_topup',
            LedgerService::CASH,
            'TEST-OB-PAYER'
        );
        app(WalletService::class)->credit(
            $beneficiary,
            10_000,
            'wallet_topup',
            LedgerService::CASH,
            'TEST-OB-BENEFICIARY'
        );

        app(GroupContext::class)->setMembership($payer->load('role', 'group'));

        return [
            'group' => $group,
            'payer' => $payer,
            'payerUser' => $payerUser,
            'beneficiary' => $beneficiary,
            'adminUser' => $adminUser,
            'account' => $account,
            'loan' => $loan,
            'bill' => $bill,
            'contribution' => $contribution,
        ];
    }

    private function enable(Group $group): void
    {
        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => OnBehalfPayments::SETTING_KEY],
            ['value' => true]
        );
    }

    private function walletBalance(Member $member): int
    {
        return (int) Wallet::withoutGlobalScopes()->where('member_id', $member->id)->sum('available_balance');
    }
}

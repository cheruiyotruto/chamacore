<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundCycle;
use App\Models\MerryGoRoundParticipant;
use App\Models\PaymentIntent;
use App\Models\Role;
use App\Models\User;
use App\Services\Gateways\MpesaGateway;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\GroupContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalMgrPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_pay_their_own_contribution_from_their_wallet(): void
    {
        $fixture = $this->mgrFixture();
        $contribution = $fixture['payerContribution'];

        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.mgr.contributions.pay-wallet', $contribution))
            ->assertRedirect();

        $this->assertSame('paid', $contribution->fresh()->status);
        $this->assertNotNull($contribution->fresh()->paid_at);
        $this->assertSame(15_000, $fixture['payer']->fresh()->wallet->available_balance);
        $this->assertSame('pending', $fixture['otherContribution']->fresh()->status);
    }

    public function test_member_cannot_pay_another_members_contribution(): void
    {
        $fixture = $this->mgrFixture();

        // A member-scoped route binding hides another member's rows entirely.
        $this->actingAs($fixture['payerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.mgr.contributions.pay-wallet', $fixture['otherContribution']))
            ->assertNotFound();

        $this->assertSame('pending', $fixture['otherContribution']->fresh()->status);
        $this->assertSame(20_000, $fixture['payer']->fresh()->wallet->available_balance);
    }

    public function test_staff_cannot_pay_a_contribution_owned_by_another_member(): void
    {
        $fixture = $this->mgrFixture();

        $role = Role::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('key', 'group_admin')
            ->firstOrFail();

        $staffUser = User::factory()->create();
        Member::factory()->forGroup($fixture['group'])->forUser($staffUser)->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->actingAs($staffUser)
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.mgr.contributions.pay-wallet', $fixture['otherContribution']))
            ->assertForbidden();

        $this->assertSame('pending', $fixture['otherContribution']->fresh()->status);
    }

    public function test_mpesa_contribution_intent_and_callback_settle_the_contribution(): void
    {
        $fixture = $this->mgrFixture();
        $contribution = $fixture['payerContribution'];

        $gateway = \Mockery::mock(MpesaGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('initiate')->once()->andReturn([
            'reference' => 'MGR-CHECKOUT',
            'response' => ['CheckoutRequestID' => 'MGR-CHECKOUT'],
        ]);
        $this->app->instance(MpesaGateway::class, $gateway);

        app(GroupContext::class)->setMembership($fixture['payer']->load('role', 'group'));

        $intent = app(PaymentService::class)->initiate($fixture['payer'], [
            'purpose' => 'mgr_contribution',
            'amount' => 5_000,
            'phone' => '254712345678',
            'payload' => [
                'contribution_id' => $contribution->id,
                'penalty_amount' => 0,
                'manual_charge' => null,
            ],
        ], 'mgr-test-'.$contribution->id);

        $this->assertSame('mgr_contribution', $intent->purpose);
        $this->assertSame(5_000, $intent->amount);
        $this->assertSame('mpesa', $intent->provider);
        $this->assertSame('MGR-CHECKOUT', $intent->provider_reference);
        $this->assertSame($contribution->id, (int) data_get($intent->payload, 'contribution_id'));

        $callback = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'MGR-CHECKOUT',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 5_000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MGR-RECEIPT'],
                        ],
                    ],
                ],
            ],
        ];

        $payments = app(PaymentService::class);
        $first = $payments->handleMpesaCallback($callback);
        $this->assertSame('succeeded', $first['status']);
        $this->assertSame('MGR-CHECKOUT', $intent->fresh()->provider_reference);
        $this->assertSame('duplicate', $payments->handleMpesaCallback($callback)['status']);

        $this->assertSame('succeeded', $intent->fresh()->status);
        $this->assertSame('paid', $contribution->fresh()->status);
        $this->assertSame('mpesa', $contribution->fresh()->method);
        $this->assertSame($intent->id, (int) $contribution->fresh()->payment_intent_id);
        $this->assertSame(1, PaymentIntent::withoutGlobalScopes()->where('purpose', 'mgr_contribution')->count());
    }

    /**
     * @return array{
     *     group:Group,
     *     payer:Member,
     *     payerUser:User,
     *     other:Member,
     *     payerContribution:MerryGoRoundContribution,
     *     otherContribution:MerryGoRoundContribution
     * }
     */
    private function mgrFixture(): array
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
        $otherUser = User::factory()->create();

        $payer = Member::factory()->forGroup($group)->forUser($payerUser)->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $other = Member::factory()->forGroup($group)->forUser($otherUser)->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $cycle = MerryGoRoundCycle::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Test rotation',
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

        $contributions = [];

        foreach ([$payer, $other] as $index => $member) {
            $participant = MerryGoRoundParticipant::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'merry_go_round_cycle_id' => $cycle->id,
                'member_id' => $member->id,
                'position' => $index + 1,
                'payout_date' => now()->addMonths($index)->toDateString(),
                'status' => 'active',
            ]);

            $contributions[$index] = MerryGoRoundContribution::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'merry_go_round_cycle_id' => $cycle->id,
                'participant_id' => $participant->id,
                'member_id' => $member->id,
                'amount' => 5_000,
                'due_date' => now()->addDays(5)->toDateString(),
                'status' => 'pending',
            ]);
        }

        app(WalletService::class)->credit(
            $payer,
            20_000,
            'wallet_topup',
            LedgerService::CASH,
            'TEST-MGR-TOP-UP'
        );

        app(GroupContext::class)->setMembership($payer->load('role', 'group'));

        return [
            'group' => $group,
            'payer' => $payer,
            'payerUser' => $payerUser,
            'other' => $other,
            'payerContribution' => $contributions[0],
            'otherContribution' => $contributions[1],
        ];
    }
}

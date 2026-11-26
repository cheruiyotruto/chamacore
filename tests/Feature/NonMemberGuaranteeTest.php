<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Guarantor;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanOriginationService;
use App\Services\MemberApplicationService;
use App\Services\SavingsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NonMemberGuaranteeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_member_guarantor_is_invited_then_funds_the_guarantee_after_approval(): void
    {
        Notification::fake();
        $sms = $this->fakeSms();
        $fixture = $this->groupFixture();

        $this->actingAs($fixture['borrowerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.loans.store'), [
                'loan_product_id' => $fixture['product']->id,
                'amount' => '2000.00',
                'duration' => 12,
                'duration_unit' => 'months',
                'non_member_guarantors' => [[
                    'email' => 'guarantor@example.test',
                    'phone' => '0712345678',
                ]],
            ])
            ->assertRedirect();

        $loan = $fixture['group']->loans()->firstOrFail();
        $guarantee = $loan->guarantors()->firstOrFail();
        $invitation = MemberInvitation::withoutGlobalScopes()->findOrFail($guarantee->invitation_id);

        $this->assertSame(Guarantor::STATUS_INVITED, $guarantee->status);
        $this->assertNull($guarantee->guarantor_member_id);
        $this->assertSame('guarantor@example.test', $guarantee->invitee_email);
        $this->assertSame('0712345678', $guarantee->invitee_phone);
        $this->assertNotNull($guarantee->required_savings_amount);
        $this->assertGreaterThan(0, $guarantee->required_savings_amount);
        $this->assertSame(['email', 'sms'], $invitation->channels);
        $this->assertSame(route('join.show', ['token' => $invitation->token]), $invitation->joinUrl());
        $this->assertStringContainsString('join', $sms->messages[0]);
        $this->assertStringContainsString($invitation->joinUrl(), $sms->messages[0]);
        $this->assertStringContainsString('savings', $sms->messages[0]);
        $this->assertSame('sent', $invitation->delivery['email']['status']);
        $this->assertSame('sent', $invitation->delivery['sms']['status']);

        // The loan must stay blocked while the invitation is unfunded.
        $this->assertTrue($loan->guarantors()
            ->whereIn('status', Guarantor::UNFULFILLED_STATUSES)
            ->exists());

        $newUser = User::factory()->create(['email' => 'guarantor@example.test']);
        $newMember = Member::factory()
            ->forGroup($fixture['group'])
            ->forUser($newUser)
            ->create([
                'role_id' => $fixture['memberRole']->id,
                'email' => 'guarantor@example.test',
                'phone' => '0712345678',
                'status' => 'applied',
                'kyc_status' => 'pending',
                'metadata' => ['invitation_id' => $invitation->id],
            ]);

        $invitation->update([
            'status' => MemberInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_member_id' => $newMember->id,
        ]);

        app(MemberApplicationService::class)->approve($newMember, $fixture['borrowerUser'], true);

        $guarantee->refresh();
        $this->assertSame(Guarantor::STATUS_AWAITING_SAVINGS, $guarantee->status);
        $this->assertSame($newMember->id, $guarantee->guarantor_member_id);

        $account = SavingsAccount::withoutGlobalScopes()
            ->where('member_id', $newMember->id)
            ->where('savings_product_id', $fixture['savingsProduct']->id)
            ->firstOrFail();

        app(SavingsService::class)->deposit(
            $newMember,
            $account,
            (int) $guarantee->required_savings_amount,
            'deposit',
            LedgerService::CASH,
            'non-member-guarantee-'.$guarantee->id
        );

        $guarantee->refresh();
        $this->assertSame(Guarantor::STATUS_PENDING, $guarantee->status);
        $this->assertNotNull($guarantee->requested_at);
        $this->assertNotNull($guarantee->funded_at);
        $this->assertGreaterThanOrEqual($guarantee->guaranteed_amount, (int) $guarantee->capacity_at_request);

        $requestLog = NotificationLog::withoutGlobalScopes()
            ->where('member_id', $newMember->id)
            ->where('template_key', 'guarantor_request')
            ->firstOrFail();

        $this->assertSame(
            route('portal.loans.index', absolute: true).'#guarantee-'.$guarantee->id,
            $requestLog->payload['portal_url']
        );
    }

    public function test_a_phone_only_guardian_is_invited_by_sms_and_can_be_sign_requested_after_funding(): void
    {
        Notification::fake();
        $sms = $this->fakeSms();
        $fixture = $this->groupFixture();

        $guarantee = app(LoanOriginationService::class)->apply($fixture['borrower'], $fixture['product'], [
            'amount' => 200_000,
            'duration' => 12,
            'duration_unit' => 'months',
            'guarantors' => [[
                'phone' => '0799999999',
                'channels' => ['sms'],
            ]],
        ], $fixture['borrowerUser'])->guarantors()->firstOrFail();

        $this->assertNull($guarantee->invitee_email);
        $this->assertSame('0799999999', $guarantee->invitee_phone);
        $this->assertSame(['sms'], $guarantee->invitation->channels);
        $this->assertStringContainsString($guarantee->invitation->joinUrl(), $sms->messages[0]);

        $newUser = User::factory()->create();
        $newMember = Member::factory()
            ->forGroup($fixture['group'])
            ->forUser($newUser)
            ->create([
                'role_id' => $fixture['memberRole']->id,
                'phone' => '0799999999',
                'status' => 'applied',
                'metadata' => ['invitation_id' => $guarantee->invitation_id],
            ]);

        app(MemberApplicationService::class)->approve($newMember, $fixture['borrowerUser'], true);

        $account = SavingsAccount::withoutGlobalScopes()
            ->where('member_id', $newMember->id)
            ->firstOrFail();

        app(SavingsService::class)->deposit(
            $newMember,
            $account,
            (int) $guarantee->fresh()->required_savings_amount,
            'deposit',
            LedgerService::CASH,
            'phone-guarantee-'.$guarantee->id
        );

        $guarantee->refresh();
        $this->assertSame(Guarantor::STATUS_PENDING, $guarantee->status);

        $this->actingAs($newUser)
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.guarantees.respond', $guarantee), ['decision' => 'accept'])
            ->assertRedirect(route('portal.guarantees.sign', $guarantee));

        $this->assertSame(Guarantor::STATUS_ACCEPTED, $guarantee->fresh()->status);
    }

    public function test_a_non_member_guarantee_cannot_be_approved_before_it_is_funded(): void
    {
        $fixture = $this->groupFixture();

        $loan = app(LoanOriginationService::class)->apply($fixture['borrower'], $fixture['product'], [
            'amount' => 200_000,
            'duration' => 12,
            'guarantors' => [['email' => 'unfunded@example.test']],
        ], $fixture['borrowerUser']);

        $this->assertSame(Guarantor::STATUS_INVITED, $loan->guarantors()->firstOrFail()->status);

        try {
            app(LoanOriginationService::class)->approve(
                $loan,
                $fixture['borrowerUser'],
                'approved'
            );
            $this->fail('Loan approval should be blocked while a guarantee is unfunded.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('All guarantors must respond', $e->getMessage());
        }
    }

    /**
     * @return array{group:Group, borrower:Member, borrowerUser:User, memberRole:Role, savingsProduct:SavingsProduct, product:LoanProduct}
     */
    private function groupFixture(): array
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

        $borrowerUser = User::factory()->create();
        $borrower = Member::factory()->forGroup($group)->forUser($borrowerUser)->create([
            'role_id' => $memberRole->id,
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);

        $savingsProduct = SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Regular savings',
            'code' => 'REG-NM-'.substr(md5('nm-'.$group->id), 0, 8),
            'type' => 'voluntary',
            'qualifies_for_loan' => true,
            'is_active' => true,
        ]);

        SavingsAccount::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $borrower->id,
            'savings_product_id' => $savingsProduct->id,
            'account_no' => 'SAV-NM-B-'.$group->id,
            'balance' => 1_000_000,
            'available_balance' => 1_000_000,
            'status' => 'active',
        ]);

        app(LedgerService::class)->post($group, 'Test funding', [
            ['code' => LedgerService::CASH, 'debit' => 5_000_000],
            ['code' => LedgerService::GROUP_EQUITY, 'credit' => 5_000_000],
        ]);

        $product = LoanProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Business loan',
            'code' => 'BIZ-NM-'.substr(md5('nm-loan-'.$group->id), 0, 8),
            'category' => 'business',
            'interest_method' => 'flat',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'accrual_mode' => 'simple',
            'day_count' => 'actual_365',
            'repayment_frequency' => 'monthly',
            'duration_min' => 1,
            'duration_max' => 24,
            'duration_unit' => 'months',
            'min_amount' => 100_000,
            'max_amount' => 5_000_000,
            'multiplier_bps' => 30_000,
            'guarantee_multiplier_bps' => 30_000,
            'share_weight_bps' => 10_000,
            'requires_guarantors' => true,
            'min_guarantors' => 1,
            'guarantor_coverage_bps' => 10_000,
            'processing_fee_bps' => 150,
            'insurance_fee_bps' => 25,
            'min_membership_days' => 0,
            'max_concurrent_loans' => 2,
            'requires_no_arrears' => false,
            'is_active' => true,
        ]);

        return [
            'group' => $group,
            'borrower' => $borrower,
            'borrowerUser' => $borrowerUser,
            'memberRole' => $memberRole,
            'savingsProduct' => $savingsProduct,
            'product' => $product,
        ];
    }

    private function fakeSms(): object
    {
        $sms = new class implements SmsGateway
        {
            /** @var array<int, string> */
            public array $messages = [];

            public function send(string $recipient, string $message): array
            {
                $this->messages[] = $message;

                return ['success' => true, 'reference' => 'test', 'error' => null];
            }
        };

        $this->app->instance(SmsGateway::class, $sms);

        return $sms;
    }
}

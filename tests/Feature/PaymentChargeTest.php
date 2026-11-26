<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\BillService;
use App\Services\Gateways\MpesaGateway;
use App\Services\LedgerService;
use App\Services\PaymentChargeService;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\GroupContext;
use App\Support\PaymentChargeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_overrides_platform_defaults_and_disabled_rules_do_not_charge(): void
    {
        [$group] = $this->groupWithMember();

        $platform = PaymentChargeSettings::defaults();
        $platform[PaymentChargeSettings::BILL_PAYMENTS] = [
            ...$platform[PaymentChargeSettings::BILL_PAYMENTS],
            'enabled' => true,
            'mode' => PaymentChargeSettings::MODE_PERCENT,
            'rate_bps' => 150,
        ];
        $platform[PaymentChargeSettings::ALL_PAYMENTS] = [
            ...$platform[PaymentChargeSettings::ALL_PAYMENTS],
            'enabled' => true,
            'mode' => PaymentChargeSettings::MODE_FIXED,
            'amount' => 500,
        ];

        PlatformSetting::create([
            'key' => PaymentChargeSettings::KEY,
            'value' => PaymentChargeSettings::normalize($platform),
        ]);

        // A partial group override inherits every platform field it omits.
        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => PaymentChargeSettings::KEY],
            ['value' => [PaymentChargeSettings::BILL_PAYMENTS => ['enabled' => false]]]
        );

        $service = app(PaymentChargeService::class);

        $disabled = $service->quote($group, 10_000, [PaymentChargeSettings::BILL_PAYMENTS]);
        $this->assertSame(0, $disabled['total_fee']);

        $inherited = $service->quote($group, 10_000, [PaymentChargeSettings::ALL_PAYMENTS]);
        $this->assertSame(500, $inherited['total_fee']);
        $this->assertSame(10_500, $inherited['payable_by_payer']);
    }

    public function test_percentage_fixed_fixed_percentage_and_manual_fees_are_calculated(): void
    {
        [$group] = $this->groupWithMember();

        $cases = [
            [PaymentChargeSettings::MODE_PERCENT, 150, 0, null, 150],
            [PaymentChargeSettings::MODE_FIXED, 0, 2_000, null, 2_000],
            [PaymentChargeSettings::MODE_FIXED_PERCENT, 150, 1_000, null, 1_150],
            [PaymentChargeSettings::MODE_MANUAL, 0, 0, 3_000, 3_000],
        ];

        foreach ($cases as [$mode, $rateBps, $amount, $manual, $expected]) {
            $rules = PaymentChargeSettings::defaults();
            $rules[PaymentChargeSettings::ALL_PAYMENTS] = [
                ...$rules[PaymentChargeSettings::ALL_PAYMENTS],
                'enabled' => true,
                'mode' => $mode,
                'rate_bps' => $rateBps,
                'amount' => $amount,
            ];
            $this->saveGroupRules($group, $rules);

            // Manual charges are restricted to staff with charge/payment
            // management rights after the security hardening.
            if ($mode === PaymentChargeSettings::MODE_MANUAL) {
                $this->actAsGroupAdmin($group);
            }

            $quote = app(PaymentChargeService::class)->quote(
                $group,
                10_000,
                [PaymentChargeSettings::ALL_PAYMENTS],
                $manual
            );

            $this->assertSame($expected, $quote['total_fee'], "Fee mode {$mode}");
        }
    }

    public function test_payer_payee_and_group_borne_fees_produce_balanced_journals(): void
    {
        [$group, $payer] = $this->groupWithMember();
        [, $payee] = $this->groupWithMember($group);

        foreach ([
            PaymentChargeSettings::BORNE_BY_PAYER => [1_000, 11_000, 10_000, 0],
            PaymentChargeSettings::BORNE_BY_PAYEE => [1_000, 10_000, 9_000, 0],
            PaymentChargeSettings::BORNE_BY_GROUP => [1_000, 10_000, 10_000, 1_000],
        ] as $borneBy => [$fee, $payable, $net, $groupFee]) {
            $rules = PaymentChargeSettings::defaults();
            $rules[PaymentChargeSettings::ALL_PAYMENTS] = [
                ...$rules[PaymentChargeSettings::ALL_PAYMENTS],
                'enabled' => true,
                'mode' => PaymentChargeSettings::MODE_FIXED,
                'amount' => 1_000,
                'borne_by' => $borneBy,
            ];
            $this->saveGroupRules($group, $rules);

            $service = app(PaymentChargeService::class);
            $quote = $service->quote($group, 10_000, [PaymentChargeSettings::ALL_PAYMENTS]);

            $this->assertSame($fee, $quote['total_fee']);
            $this->assertSame($payable, $quote['payable_by_payer']);
            $this->assertSame($net, $quote['net_to_payee']);
            $this->assertSame($groupFee, $quote['group_fee']);

            $journal = $service->postPaymentJournal($group, $quote, [
                'counter_code' => LedgerService::BANK,
                'payer' => $payer,
                'payee' => $payee,
                'payee_account_code' => LedgerService::BILL_PAYABLE,
                'reference' => 'TEST-'.$borneBy,
            ]);

            $this->assertSame(
                $journal->lines()->sum('debit'),
                $journal->lines()->sum('credit'),
                "Journal for {$borneBy} is unbalanced."
            );
        }
    }

    public function test_wallet_bill_payment_debits_the_payer_and_records_the_charge(): void
    {
        [$group, $payer] = $this->groupWithMember();
        $this->actingAs($payer->user);
        app(GroupContext::class)->setMembership($payer);

        $wallets = app(WalletService::class);
        $wallets->credit($payer, 50_000, 'test_credit', LedgerService::CASH, 'TEST-FUND');

        $rules = PaymentChargeSettings::defaults();
        $rules[PaymentChargeSettings::BILL_PAYMENTS] = [
            ...$rules[PaymentChargeSettings::BILL_PAYMENTS],
            'enabled' => true,
            'mode' => PaymentChargeSettings::MODE_FIXED,
            'amount' => 2_000,
        ];
        $this->saveGroupRules($group, $rules);

        $bill = app(BillService::class)->create($group, [
            'member_id' => $payer->id,
            'payee_name' => 'County Water',
            'payee_account' => 'ACC-123',
            'amount' => 10_000,
            'status' => Bill::STATUS_PENDING,
        ], $payer->user);

        $payment = app(BillService::class)->payWithWallet($payer, $bill, 10_000, null, $payer->user);

        $this->assertSame(2_000, $payment->charge_amount);
        $this->assertSame(10_000, $bill->refresh()->amount_paid);
        $this->assertSame(Bill::STATUS_PAID, $bill->status);
        $this->assertSame(38_000, $payer->wallet()->firstOrFail()->balance);

        $journal = $payment->journalEntry;
        $this->assertSame($journal->lines()->sum('debit'), $journal->lines()->sum('credit'));
        $this->assertSame(
            12_000,
            (int) $journal->lines()->where('debit', '>', 0)->sum('debit')
        );
    }

    public function test_multiple_matching_rules_are_additive(): void
    {
        [$group] = $this->groupWithMember();

        $rules = PaymentChargeSettings::defaults();
        $rules[PaymentChargeSettings::ALL_PAYMENTS] = [
            ...$rules[PaymentChargeSettings::ALL_PAYMENTS],
            'enabled' => true,
            'mode' => PaymentChargeSettings::MODE_FIXED,
            'amount' => 500,
        ];
        $rules[PaymentChargeSettings::BILL_PAYMENTS] = [
            ...$rules[PaymentChargeSettings::BILL_PAYMENTS],
            'enabled' => true,
            'mode' => PaymentChargeSettings::MODE_PERCENT,
            'rate_bps' => 100,
        ];
        $this->saveGroupRules($group, $rules);

        $quote = app(PaymentChargeService::class)->quote(
            $group,
            10_000,
            PaymentChargeSettings::categoriesFor('bill_payment', 'mpesa')
        );

        // All payments (500) + bill payments (1% = 100) + M-Pesa rule disabled.
        $this->assertSame(600, $quote['total_fee']);
        $this->assertCount(2, $quote['lines']);
    }

    public function test_mpesa_callback_applies_the_payer_charge_on_top_of_the_top_up(): void
    {
        [$group, $member] = $this->groupWithMember();
        $this->actingAs($member->user);
        app(GroupContext::class)->setMembership($member);

        $gateway = Mockery::mock(MpesaGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('initiate')->once()->andReturn([
            'reference' => 'CHECKOUT-CHARGE',
            'response' => ['CheckoutRequestID' => 'CHECKOUT-CHARGE'],
        ]);
        $this->app->instance(MpesaGateway::class, $gateway);

        $rules = PaymentChargeSettings::defaults();
        $rules[PaymentChargeSettings::WALLET_DEPOSITS] = [
            ...$rules[PaymentChargeSettings::WALLET_DEPOSITS],
            'enabled' => true,
            'mode' => PaymentChargeSettings::MODE_FIXED,
            'amount' => 1_000,
        ];
        $this->saveGroupRules($group, $rules);

        /** @var PaymentService $payments */
        $payments = app(PaymentService::class);

        $intent = $payments->initiate($member, [
            'purpose' => 'wallet_topup',
            'amount' => 10_000,
            'phone' => '254712345678',
        ]);

        $this->assertSame(11_000, $intent->amount);

        $result = $payments->handleMpesaCallback([
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'CHECKOUT-CHARGE',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 11_000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RECEIPT-CHARGE'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('succeeded', $intent->refresh()->status);
        $this->assertSame(10_000, $member->wallet()->firstOrFail()->balance);

        $chargeJournals = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('description', 'like', '%Payment charges%')
            ->get();

        $this->assertCount(1, $chargeJournals);
        $this->assertSame(1_000, (int) $chargeJournals->first()->lines()->sum('credit'));
        $this->assertTrue(PaymentIntent::withoutGlobalScopes()->whereKey($intent->id)->exists());
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     */
    private function saveGroupRules(Group $group, array $rules): void
    {
        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => PaymentChargeSettings::KEY],
            ['value' => PaymentChargeSettings::normalize($rules)]
        );
    }

    private function actAsGroupAdmin(Group $group): void
    {
        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'group_admin'],
            [
                'name' => config('chama.roles.group_admin', 'Group admin'),
                'permissions' => config('chama.role_permissions.group_admin', []),
                'is_system' => true,
            ]
        );

        $user = User::factory()->create();
        $admin = Member::factory()->forGroup($group)->forUser($user)->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        app(GroupContext::class)->setMembership($admin->load('role', 'group'));
    }

    /**
     * @return array{0: Group, 1: Member}
     */
    private function groupWithMember(?Group $group = null): array
    {
        $group ??= Group::factory()->create();

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
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
                'membership_category' => 'ordinary',
            ]);

        return [$group, $member];
    }
}

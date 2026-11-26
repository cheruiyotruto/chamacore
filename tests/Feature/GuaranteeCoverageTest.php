<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Guarantor;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanOriginationService;
use App\Services\MultiplierEngine;
use App\Support\GuaranteeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuaranteeCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_obligation_mode_uses_the_quoted_principal_interest_and_fees(): void
    {
        [$group, $borrower, $guarantor] = $this->loanFixture();
        $product = $this->product($group);

        $quote = app(LoanOriginationService::class)
            ->quotation($borrower, $product, 200_000, 12, 'months')['quote'];

        $loan = app(LoanOriginationService::class)->apply($borrower, $product, [
            'amount' => 200_000,
            'duration' => 12,
            'duration_unit' => 'months',
            'guarantors' => [['member_id' => $guarantor->id]],
        ], User::factory()->create());

        $row = $loan->guarantors()->firstOrFail();

        $this->assertSame($quote->totalRepayable, (int) $row->guaranteed_amount);
        $this->assertSame(200_000, (int) $row->principal_amount);
        $this->assertSame($quote->totalRepayable, (int) $row->full_obligation_amount);
        $this->assertSame(GuaranteeSettings::COVERAGE_FULL_OBLIGATION, $row->coverage_basis);
        $this->assertGreaterThan(200_000, (int) $row->guaranteed_amount);
    }

    public function test_principal_only_mode_keeps_the_legacy_guarantee_amount(): void
    {
        [$group, $borrower, $guarantor] = $this->loanFixture();
        $product = $this->product($group);

        $this->saveSettings($group, [
            'new_guarantees' => GuaranteeSettings::COVERAGE_PRINCIPAL,
            'existing_commitments' => GuaranteeSettings::COVERAGE_PRINCIPAL,
        ]);

        $quote = app(LoanOriginationService::class)
            ->quotation($borrower, $product, 200_000, 12, 'months')['quote'];

        $loan = app(LoanOriginationService::class)->apply($borrower, $product, [
            'amount' => 200_000,
            'duration' => 12,
            'duration_unit' => 'months',
            'guarantors' => [['member_id' => $guarantor->id]],
        ], User::factory()->create());

        $row = $loan->guarantors()->firstOrFail();

        $this->assertSame(200_000, (int) $row->guaranteed_amount);
        $this->assertSame(200_000, (int) $row->principal_amount);
        $this->assertSame($quote->totalRepayable, (int) $row->full_obligation_amount);
        $this->assertSame(GuaranteeSettings::COVERAGE_PRINCIPAL, $row->coverage_basis);
    }

    public function test_admin_can_choose_how_existing_commitments_reduce_capacity(): void
    {
        [$group, $borrower, $guarantor] = $this->loanFixture();
        $product = $this->product($group);

        $quote = app(LoanOriginationService::class)
            ->quotation($borrower, $product, 200_000, 12, 'months')['quote'];

        app(LoanOriginationService::class)->apply($borrower, $product, [
            'amount' => 200_000,
            'duration' => 12,
            'duration_unit' => 'months',
            'guarantors' => [['member_id' => $guarantor->id]],
        ], User::factory()->create());

        $multiplier = app(MultiplierEngine::class);

        $full = $multiplier->guarantorCapacity($guarantor->refresh(), $product);
        $this->assertSame($quote->totalRepayable, (int) $full['committed']);

        $this->saveSettings($group, [
            'new_guarantees' => GuaranteeSettings::COVERAGE_FULL_OBLIGATION,
            'existing_commitments' => GuaranteeSettings::COVERAGE_PRINCIPAL,
        ]);

        $principal = $multiplier->guarantorCapacity($guarantor->refresh(), $product);
        $this->assertSame(200_000, (int) $principal['committed']);

        $borrowingCapacity = $multiplier->calculate($guarantor->refresh(), $product, 0, false);
        $this->assertSame(200_000, (int) $borrowingCapacity['guarantee_commitments']);
    }

    public function test_a_group_admin_can_save_the_guarantee_cover_settings(): void
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
        $user = User::factory()->create();
        Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create(['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Guarantee cover');

        $this->actingAs($user)
            ->put(route('settings.guarantees.update'), [
                'new_guarantees' => GuaranteeSettings::COVERAGE_PRINCIPAL,
                'existing_commitments' => GuaranteeSettings::COVERAGE_FULL_OBLIGATION,
            ])
            ->assertRedirect();

        $settings = GuaranteeSettings::for($group);

        $this->assertSame(GuaranteeSettings::COVERAGE_PRINCIPAL, $settings->newGuarantees());
        $this->assertSame(GuaranteeSettings::COVERAGE_FULL_OBLIGATION, $settings->existingCommitments());
    }

    /**
     * @return array{0: Group, 1: Member, 2: Member}
     */
    private function loanFixture(): array
    {
        $group = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        $borrower = Member::factory()->forGroup($group)->create([
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);
        $guarantor = Member::factory()->forGroup($group)->create([
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);

        $savingsProduct = SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Regular savings',
            'code' => 'REG-'.strtolower(substr(md5((string) $group->id), 0, 5)),
            'type' => 'voluntary',
            'qualifies_for_loan' => true,
            'is_active' => true,
        ]);

        foreach ([$borrower, $guarantor] as $index => $member) {
            SavingsAccount::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'member_id' => $member->id,
                'savings_product_id' => $savingsProduct->id,
                'account_no' => 'SAV-'.$group->id.'-'.$index,
                'balance' => 1_000_000,
                'available_balance' => 1_000_000,
                'status' => 'active',
            ]);
        }

        $ledger = app(LedgerService::class);
        $ledger->bootstrap($group);
        $ledger->post($group, 'Test funding', [
            ['code' => LedgerService::CASH, 'debit' => 5_000_000],
            ['code' => LedgerService::GROUP_EQUITY, 'credit' => 5_000_000],
        ]);

        return [$group, $borrower, $guarantor];
    }

    private function product(Group $group): LoanProduct
    {
        return LoanProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Business loan',
            'code' => 'BIZ-'.strtolower(substr(md5((string) $group->id), 0, 5)),
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
    }

    /**
     * @param  array<string, string>  $values
     */
    private function saveSettings(Group $group, array $values): void
    {
        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => GuaranteeSettings::SETTING_KEY],
            ['value' => GuaranteeSettings::normalize($values)]
        );
    }
}

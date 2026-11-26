<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_a_custom_loan_type(): void
    {
        [$group, $admin] = $this->userWithRole('group_admin');

        $this->actingAs($admin)
            ->post(route('loan-products.store'), $this->payload([
                'name' => 'School fees loan',
                'code' => 'school-fees',
                'category' => LoanProduct::CUSTOM_CATEGORY,
                'custom_category' => 'School fees',
            ]))
            ->assertRedirect(route('loan-products.index'))
            ->assertSessionHas('status');

        $product = LoanProduct::withoutGlobalScopes()->where('code', 'SCHOOL-FEES')->firstOrFail();

        $this->assertSame($group->id, $product->group_id);
        $this->assertSame('school_fees', $product->category);
        $this->assertSame('School Fees', $product->categoryLabel());
        $this->assertTrue($product->is_active);
    }

    public function test_a_custom_loan_type_requires_a_type_name(): void
    {
        [, $admin] = $this->userWithRole('group_admin');

        $this->actingAs($admin)
            ->from(route('loan-products.create'))
            ->post(route('loan-products.store'), $this->payload([
                'name' => 'Unnamed type',
                'category' => LoanProduct::CUSTOM_CATEGORY,
                'custom_category' => '',
            ]))
            ->assertRedirect(route('loan-products.create'))
            ->assertSessionHasErrors('custom_category');

        $this->assertSame(0, LoanProduct::withoutGlobalScopes()->count());
    }

    public function test_a_preset_loan_type_can_still_be_created(): void
    {
        [, $admin] = $this->userWithRole('group_admin');

        $this->actingAs($admin)
            ->post(route('loan-products.store'), $this->payload([
                'name' => 'Emergency loan',
                'code' => 'EMERGENCY',
                'category' => 'emergency',
            ]))
            ->assertRedirect(route('loan-products.index'));

        $this->assertSame('emergency', LoanProduct::withoutGlobalScopes()->firstOrFail()->category);
    }

    public function test_a_custom_loan_type_is_visible_to_members_when_applying(): void
    {
        [$group, $admin] = $this->userWithRole('group_admin');
        [, $user] = $this->activeMember($group);

        $this->productModel($group, [
            'name' => 'School fees loan',
            'code' => 'SCHOOL-FEES',
            'category' => 'school_fees',
        ]);

        $this->actingAs($user)
            ->get(route('portal.loans.create'))
            ->assertOk()
            ->assertSee('School fees loan')
            ->assertSee('School Fees');

        $this->actingAs($admin)
            ->get(route('loan-products.index'))
            ->assertOk()
            ->assertSee('School Fees')
            ->assertSee('New loan product');
    }

    public function test_an_admin_can_change_a_product_to_a_custom_loan_type(): void
    {
        [$group, $admin] = $this->userWithRole('group_admin');

        $product = $this->productModel($group, [
            'name' => 'Emergency loan',
            'code' => 'EMERGENCY',
            'category' => 'emergency',
        ]);

        $this->actingAs($admin)
            ->put(route('loan-products.update', $product), $this->payload([
                'name' => 'Salary advance',
                'code' => 'EMERGENCY',
                'category' => LoanProduct::CUSTOM_CATEGORY,
                'custom_category' => 'Salary advance',
            ]))
            ->assertRedirect(route('loan-products.index'));

        $product->refresh();

        $this->assertSame('salary_advance', $product->category);
        $this->assertSame('Salary advance', $product->categoryLabel());
    }

    public function test_loan_requirements_are_collapsible_on_application_forms(): void
    {
        [$group, $admin] = $this->userWithRole('group_admin');
        [, $user] = $this->activeMember($group);

        $this->productModel($group);

        $this->actingAs($admin)
            ->get(route('loans.create'))
            ->assertOk()
            ->assertSee('Product requirements')
            ->assertSee('<details', false);

        $this->actingAs($user)
            ->get(route('portal.loans.create'))
            ->assertOk()
            ->assertSee('What this product requires')
            ->assertSee('<details', false);
    }

    public function test_an_ordinary_member_cannot_manage_loan_types(): void
    {
        [$group, $user] = $this->activeMember($this->userWithRole('group_admin')[0]);

        $this->actingAs($user)->get(route('loan-products.index'))->assertForbidden();
        $this->actingAs($user)->get(route('loan-products.create'))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Business loan',
            'code' => 'BUSINESS',
            'category' => 'business',
            'description' => 'Working capital for small businesses.',
            'interest_method' => 'reducing_annuity',
            'interest_rate' => '12.00',
            'interest_period' => 'monthly',
            'accrual_mode' => 'effective',
            'day_count' => 'actual_365',
            'repayment_frequency' => 'monthly',
            'duration_min' => 3,
            'duration_max' => 24,
            'duration_unit' => 'months',
            'min_amount' => '5000',
            'max_amount' => '50000',
            'multiplier' => '300',
            'guarantee_multiplier' => '300',
            'share_weight' => '100',
            'min_guarantors' => 2,
            'guarantor_coverage' => '100',
            'guarantor_mode' => 'optional',
            'penalty_rate' => '2.00',
            'penalty_period' => 'daily',
            'penalty_grace_days' => 3,
            'processing_fee' => '1.50',
            'insurance_fee' => '0',
            'allocation_policy' => 'standard',
            'min_membership_days' => 0,
            'max_concurrent_loans' => 1,
            'requires_guarantors' => '1',
            'requires_no_arrears' => '1',
            'is_active' => '1',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function productModel(Group $group, array $overrides = []): LoanProduct
    {
        return LoanProduct::withoutGlobalScopes()->create(array_merge([
            'group_id' => $group->id,
            'name' => 'Business loan',
            'code' => 'BUSINESS',
            'category' => 'business',
            'description' => 'Working capital for small businesses.',
            'interest_method' => 'reducing_annuity',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'accrual_mode' => 'effective',
            'day_count' => 'actual_365',
            'repayment_frequency' => 'monthly',
            'duration_min' => 3,
            'duration_max' => 24,
            'duration_unit' => 'months',
            'min_amount' => 500_000,
            'max_amount' => 5_000_000,
            'multiplier_bps' => 30_000,
            'guarantee_multiplier_bps' => 30_000,
            'share_weight_bps' => 10_000,
            'requires_guarantors' => true,
            'min_guarantors' => 2,
            'guarantor_coverage_bps' => 10_000,
            'penalty_rate_bps' => 200,
            'penalty_period' => 'daily',
            'penalty_grace_days' => 3,
            'processing_fee_bps' => 150,
            'insurance_fee_bps' => 0,
            'allocation_policy' => ['penalties', 'fees', 'interest', 'principal'],
            'min_membership_days' => 0,
            'max_concurrent_loans' => 1,
            'requires_no_arrears' => true,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @return array{0: Group, 1: User}
     */
    private function userWithRole(string $roleKey): array
    {
        $group = Group::factory()->create();

        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => $roleKey],
            [
                'name' => config('chama.roles.'.$roleKey, ucfirst($roleKey)),
                'permissions' => config('chama.role_permissions.'.$roleKey, []),
                'is_system' => true,
            ]
        );

        $user = User::factory()->create();

        Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
            ]);

        return [$group, $user];
    }

    /**
     * @return array{0: Member, 1: User}
     */
    private function activeMember(Group $group): array
    {
        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'member'],
            [
                'name' => config('chama.roles.member', 'Member'),
                'permissions' => config('chama.role_permissions.member', []),
                'is_system' => true,
            ]
        );

        $user = User::factory()->create();
        $member = Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
            ]);

        return [$member, $user];
    }
}

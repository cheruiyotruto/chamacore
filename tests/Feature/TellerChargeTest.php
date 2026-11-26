<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\TellerEntry;
use App\Models\TellerSession;
use App\Models\TransactionCharge;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TellerChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mandatory_fixed_charge_is_collected_on_a_cash_deposit(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);
        $this->charge($group, ['name' => 'Deposit handling fee', 'amount' => 2_000]);

        $session = $this->openTill($teller);
        $this->deposit($teller, $member, $account, '250');

        $entry = TellerEntry::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(25_000, $entry->amount);
        $this->assertSame(2_000, $entry->charge_amount);
        $this->assertSame(0, $entry->waived_charge_amount);
        $this->assertSame(25_000, $account->refresh()->balance);
        $this->assertSame(2_000, app(LedgerService::class)->accountBalance($group, LedgerService::TELLER_CHARGE_INCOME));
        $this->assertNotNull($entry->charge_journal_entry_id);
        $this->assertSame(27_000, $session->refresh()->expectedCash());

        $this->actingAs($teller)
            ->get(route('teller.entries.receipt', $entry))
            ->assertOk()
            ->assertSee('Total received')
            ->assertSee('Deposit handling fee')
            ->assertSee('270.00');
    }

    public function test_an_optional_percentage_charge_can_be_waived_by_the_teller(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);
        $this->charge($group, [
            'name' => 'Service fee',
            'basis' => TransactionCharge::BASIS_PERCENT,
            'rate_bps' => 100,
            'is_mandatory' => false,
        ]);

        $this->openTill($teller);

        $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_deposit',
            'savings_account_id' => $account->id,
            'amount' => '100',
            'method' => 'cash',
            'waive_charges' => '1',
            'idempotency_key' => 'waived-charge',
        ]);

        $waived = TellerEntry::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame(0, $waived->charge_amount);
        $this->assertSame(100, $waived->waived_charge_amount);

        $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_deposit',
            'savings_account_id' => $account->id,
            'amount' => '100',
            'method' => 'cash',
            'idempotency_key' => 'charged-deposit',
        ]);

        $charged = TellerEntry::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame(100, $charged->charge_amount);
        $this->assertSame(100, app(LedgerService::class)->accountBalance($group, LedgerService::TELLER_CHARGE_INCOME));
    }

    public function test_a_mandatory_charge_cannot_be_waived(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);
        $this->charge($group, ['name' => 'Mandatory fee', 'amount' => 1_500, 'is_mandatory' => true]);

        $this->openTill($teller);

        $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_deposit',
            'savings_account_id' => $account->id,
            'amount' => '100',
            'method' => 'cash',
            'waive_charges' => '1',
            'idempotency_key' => 'mandatory-charge',
        ]);

        $entry = TellerEntry::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(1_500, $entry->charge_amount);
        $this->assertSame(0, $entry->waived_charge_amount);
    }

    public function test_disabled_and_out_of_scope_charges_are_not_applied(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->charge($group, ['name' => 'Disabled fee', 'amount' => 2_000, 'is_active' => false]);
        $this->charge($group, [
            'name' => 'Associate fee',
            'amount' => 3_000,
            'applies_to' => TransactionCharge::APPLIES_CATEGORIES,
            'member_categories' => ['associate'],
        ]);

        $this->openTill($teller);
        $this->deposit($teller, $member, $account, '100');

        $entry = TellerEntry::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(0, $entry->charge_amount);
        $this->assertSame(0, app(LedgerService::class)->accountBalance($group, LedgerService::TELLER_CHARGE_INCOME));
    }

    public function test_a_charge_can_target_selected_members_only(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        [$otherMember] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);
        $otherAccount = $this->savingsAccount($group, $otherMember);

        $this->charge($group, [
            'name' => 'Selected member fee',
            'amount' => 4_000,
            'applies_to' => TransactionCharge::APPLIES_MEMBERS,
            'member_ids' => [$member->id],
        ]);

        $this->openTill($teller);

        $this->deposit($teller, $otherMember, $otherAccount, '100');
        $this->assertSame(0, TellerEntry::withoutGlobalScopes()->latest('id')->firstOrFail()->charge_amount);

        $this->deposit($teller, $member, $account, '100');
        $this->assertSame(4_000, TellerEntry::withoutGlobalScopes()->latest('id')->firstOrFail()->charge_amount);
    }

    public function test_a_percentage_charge_respects_the_maximum_cap(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->charge($group, [
            'name' => 'Capped fee',
            'basis' => TransactionCharge::BASIS_PERCENT,
            'rate_bps' => 500,
            'max_amount' => 8_000,
        ]);

        $this->openTill($teller);
        $this->deposit($teller, $member, $account, '2000');

        $entry = TellerEntry::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(8_000, $entry->charge_amount);
    }

    public function test_a_cash_out_charge_is_deducted_from_the_payout(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member, 100_000);

        $this->charge($group, [
            'name' => 'Withdrawal fee',
            'category' => 'savings_withdrawal',
            'amount' => 2_000,
        ]);

        $session = $this->openTill($teller, '1000');

        $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_withdrawal',
            'savings_account_id' => $account->id,
            'amount' => '500',
            'method' => 'cash',
            'idempotency_key' => 'withdrawal-charge',
        ]);

        $entry = TellerEntry::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(50_000, $entry->amount);
        $this->assertSame(2_000, $entry->charge_amount);
        $this->assertSame(50_000, $account->refresh()->balance);
        $this->assertSame(48_000, $session->refresh()->cashOut());
        $this->assertSame(52_000, $session->expectedCash());
        $this->assertSame(2_000, app(LedgerService::class)->accountBalance($group, LedgerService::TELLER_CHARGE_INCOME));
    }

    public function test_an_administrator_can_create_and_disable_a_charge_from_settings(): void
    {
        [$group, $admin] = $this->userWithRole('group_admin');

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Transaction charges');

        $this->actingAs($admin)
            ->post(route('settings.charges.store'), [
                'name' => 'Joining day levy',
                'category' => 'savings_deposit',
                'basis' => 'fixed',
                'amount' => '25',
                'is_mandatory' => '1',
                'is_active' => '1',
                'applies_to' => 'all',
                'income_account_code' => LedgerService::TELLER_CHARGE_INCOME,
                'sort_order' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $charge = TransactionCharge::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($group->id, $charge->group_id);
        $this->assertSame(2_500, $charge->amount);
        $this->assertTrue($charge->is_mandatory);
        $this->assertTrue($charge->is_active);

        $this->actingAs($admin)
            ->put(route('settings.charges.update', $charge), [
                'name' => 'Joining day levy',
                'category' => 'savings_deposit',
                'basis' => 'fixed',
                'amount' => '25',
                'is_active' => '0',
                'applies_to' => 'all',
                'income_account_code' => LedgerService::TELLER_CHARGE_INCOME,
                'sort_order' => '1',
            ])
            ->assertRedirect();

        $this->assertFalse($charge->refresh()->is_active);
    }

    public function test_a_teller_cannot_manage_charge_settings(): void
    {
        [, $teller] = $this->userWithRole('teller');

        $this->actingAs($teller)
            ->post(route('settings.charges.store'), [
                'name' => 'Unauthorised',
                'category' => 'savings_deposit',
                'basis' => 'fixed',
                'amount' => '10',
                'applies_to' => 'all',
                'income_account_code' => LedgerService::TELLER_CHARGE_INCOME,
            ])
            ->assertForbidden();
    }

    private function deposit(User $teller, Member $member, SavingsAccount $account, string $amount): void
    {
        $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_deposit',
            'savings_account_id' => $account->id,
            'amount' => $amount,
            'method' => 'cash',
            'idempotency_key' => 'deposit-'.uniqid(),
        ]);
    }

    private function openTill(User $teller, string $openingFloat = '0'): TellerSession
    {
        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => $openingFloat,
        ]);

        return TellerSession::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function charge(Group $group, array $overrides = []): TransactionCharge
    {
        $memberIds = $overrides['member_ids'] ?? [];
        unset($overrides['member_ids']);

        $charge = TransactionCharge::withoutGlobalScopes()->create(array_merge([
            'group_id' => $group->id,
            'name' => 'Test charge',
            'category' => 'savings_deposit',
            'basis' => TransactionCharge::BASIS_FIXED,
            'amount' => 0,
            'rate_bps' => 0,
            'min_amount' => 0,
            'max_amount' => null,
            'is_mandatory' => true,
            'is_active' => true,
            'applies_to' => TransactionCharge::APPLIES_ALL,
            'member_categories' => [],
            'income_account_code' => LedgerService::TELLER_CHARGE_INCOME,
            'sort_order' => 0,
        ], $overrides));

        if ($memberIds !== []) {
            $charge->members()->sync($memberIds);
        }

        return $charge;
    }

    private function savingsAccount(Group $group, Member $member, int $balance = 0): SavingsAccount
    {
        $product = SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Ordinary savings',
            'code' => 'ORD-'.uniqid(),
            'type' => SavingsProduct::TYPE_VOLUNTARY,
            'interest_rate_bps' => 0,
            'interest_period' => 'annual',
            'min_balance' => 0,
            'allows_withdrawal' => true,
            'qualifies_for_loan' => true,
            'is_active' => true,
        ]);

        return SavingsAccount::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'savings_product_id' => $product->id,
            'account_no' => 'SA-TEST-'.$member->id.'-'.uniqid(),
            'balance' => $balance,
            'available_balance' => $balance,
            'pledged_amount' => 0,
            'status' => 'active',
            'opened_at' => now(),
        ]);
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
                'membership_category' => 'ordinary',
            ]);

        return [$member, $user];
    }
}

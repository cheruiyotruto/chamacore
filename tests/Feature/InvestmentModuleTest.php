<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\InvestmentSchedule;
use App\Models\InvestmentTransaction;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InvestmentService;
use App\Services\LedgerService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvestmentModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_quote_an_investment_before_placing_it(): void
    {
        [$group, $user] = $this->memberWithWallet();
        $product = $this->product($group);

        $this->actingAs($user)
            ->postJson(route('portal.investments.quote'), [
                'investment_product_id' => $product->id,
                'amount' => '10000',
                'start_date' => '2026-01-01',
            ])
            ->assertOk()
            ->assertJsonPath('expected_interest', 120_000)
            ->assertJsonPath('expected_total', 1_120_000)
            ->assertJsonPath('period_count', 12)
            ->assertJsonPath('maturity_date', '2027-01-01')
            ->assertJsonCount(12, 'schedule');
    }

    public function test_a_member_can_fund_an_investment_from_their_wallet(): void
    {
        [$group, $user, $member, $wallet] = $this->memberWithWallet();
        $product = $this->product($group);

        $response = $this->actingAs($user)->post(route('portal.investments.store'), [
            'investment_product_id' => $product->id,
            'amount' => '10000',
            'start_date' => now()->toDateString(),
            'notes' => 'Salary savings',
            'terms' => '1',
        ]);

        $investment = Investment::withoutGlobalScopes()->firstOrFail();

        $response->assertRedirect(route('portal.investments.show', $investment));
        $response->assertSessionHas('status');

        $this->assertSame($member->id, $investment->member_id);
        $this->assertSame(1_000_000, $investment->principal);
        $this->assertSame(120_000, $investment->expected_interest);
        $this->assertSame(Investment::STATUS_ACTIVE, $investment->status);
        $this->assertSame(12, InvestmentSchedule::withoutGlobalScopes()->where('investment_id', $investment->id)->count());

        $wallet->refresh();
        $this->assertSame(4_000_000, $wallet->balance);
        $this->assertSame(4_000_000, $wallet->available_balance);

        $deposit = InvestmentTransaction::withoutGlobalScopes()
            ->where('investment_id', $investment->id)
            ->where('type', InvestmentTransaction::TYPE_DEPOSIT)
            ->firstOrFail();

        $this->assertSame(1_000_000, $deposit->amount);
        $this->assertSame('credit', $deposit->direction);

        $ledger = app(LedgerService::class);
        $this->assertSame(1_000_000, $ledger->accountBalance($group, LedgerService::INVESTMENT_LIABILITY));
        $this->assertSame($ledger->totalDebits($group), $ledger->totalCredits($group));
    }

    public function test_due_interest_is_accrued_once_and_is_idempotent(): void
    {
        [$group, , $member] = $this->memberWithWallet();
        $product = $this->product($group);
        $service = app(InvestmentService::class);

        $investment = $service->open(
            member: $member,
            product: $product,
            amount: 1_000_000,
            startDate: now()->subMonths(3)->toDateString(),
        );

        $first = $service->accrueDueInterest($investment);
        $second = $service->accrueDueInterest($investment->refresh());

        $this->assertSame(30_000, $first);
        $this->assertSame(0, $second);

        $investment->refresh();
        $this->assertSame(30_000, $investment->accrued_interest);
        $this->assertSame(3, InvestmentSchedule::withoutGlobalScopes()
            ->where('investment_id', $investment->id)
            ->where('status', InvestmentSchedule::STATUS_ACCRUED)
            ->count());
        $this->assertSame(3, InvestmentTransaction::withoutGlobalScopes()
            ->where('investment_id', $investment->id)
            ->where('type', InvestmentTransaction::TYPE_INTEREST)
            ->count());

        $ledger = app(LedgerService::class);
        $this->assertSame(30_000, $ledger->accountBalance($group, LedgerService::INVESTMENT_INTEREST_EXPENSE));
        $this->assertSame(
            1_030_000,
            $ledger->accountBalance($group, LedgerService::INVESTMENT_LIABILITY)
        );
    }

    public function test_early_withdrawal_is_blocked_when_the_product_locks_funds_until_maturity(): void
    {
        [$group, $user, $member, $wallet] = $this->memberWithWallet();
        $product = $this->product($group, ['early_withdrawal_allowed' => false]);

        $investment = app(InvestmentService::class)->open(
            member: $member,
            product: $product,
            amount: 1_000_000,
        );

        $balanceBefore = $wallet->refresh()->balance;

        $this->actingAs($user)
            ->from(route('portal.investments.show', $investment))
            ->post(route('portal.investments.withdraw', $investment))
            ->assertRedirect(route('portal.investments.show', $investment))
            ->assertSessionHas('error');

        $investment->refresh();
        $wallet->refresh();

        $this->assertSame(Investment::STATUS_ACTIVE, $investment->status);
        $this->assertSame($balanceBefore, $wallet->balance);
        $this->assertSame(0, InvestmentTransaction::withoutGlobalScopes()
            ->where('investment_id', $investment->id)
            ->where('type', InvestmentTransaction::TYPE_WITHDRAWAL)
            ->count());
    }

    public function test_early_withdrawal_pays_out_the_balance_less_the_penalty(): void
    {
        [$group, $user, $member, $wallet] = $this->memberWithWallet();
        $product = $this->product($group, [
            'early_withdrawal_allowed' => true,
            'early_withdrawal_penalty_bps' => 1000,
        ]);

        $investment = app(InvestmentService::class)->open(
            member: $member,
            product: $product,
            amount: 1_000_000,
        );

        Investment::withoutGlobalScopes()
            ->whereKey($investment->id)
            ->update(['accrued_interest' => 100_000]);

        $this->actingAs($user)
            ->post(route('portal.investments.withdraw', $investment))
            ->assertRedirect(route('portal.investments.show', $investment))
            ->assertSessionHas('status');

        $investment->refresh();
        $wallet->refresh();

        $this->assertSame(Investment::STATUS_WITHDRAWN, $investment->status);
        $this->assertSame(100_000, $investment->paid_interest);
        $this->assertSame(5_090_000, $wallet->balance);

        $withdrawal = InvestmentTransaction::withoutGlobalScopes()
            ->where('investment_id', $investment->id)
            ->where('type', InvestmentTransaction::TYPE_WITHDRAWAL)
            ->firstOrFail();

        $this->assertSame(1_090_000, $withdrawal->amount);
        $this->assertTrue(InvestmentTransaction::withoutGlobalScopes()
            ->where('investment_id', $investment->id)
            ->where('type', InvestmentTransaction::TYPE_PENALTY)
            ->where('amount', 10_000)
            ->exists());

        $ledger = app(LedgerService::class);
        $this->assertSame(10_000, $ledger->accountBalance($group, LedgerService::OTHER_INCOME));
        $this->assertSame($ledger->totalDebits($group), $ledger->totalCredits($group));
    }

    public function test_staff_can_create_and_toggle_an_investment_product(): void
    {
        [$group, $user] = $this->memberWithWallet('treasurer');

        $this->actingAs($user)
            ->post(route('investments.products.store'), [
                'name' => 'Fixed Deposit 24',
                'code' => 'fixed01',
                'type' => InvestmentProduct::TYPE_FIXED_TERM,
                'interest_rate' => 12.5,
                'interest_period' => InvestmentProduct::PERIOD_QUARTERLY,
                'interest_method' => InvestmentProduct::METHOD_COMPOUND,
                'duration_months' => 24,
                'min_amount' => '5000',
                'max_amount' => '100000',
                'early_withdrawal_allowed' => '1',
                'early_withdrawal_penalty' => 2.5,
                'terms' => 'Funds are locked for six months.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $product = InvestmentProduct::withoutGlobalScopes()->where('code', 'FIXED01')->firstOrFail();

        $this->assertSame($group->id, $product->group_id);
        $this->assertSame(1250, $product->interest_rate_bps);
        $this->assertSame(500_000, $product->min_amount);
        $this->assertSame(10_000_000, $product->max_amount);
        $this->assertSame(250, $product->early_withdrawal_penalty_bps);
        $this->assertTrue($product->early_withdrawal_allowed);
        $this->assertTrue($product->is_active);

        $this->actingAs($user)
            ->post(route('investments.products.toggle', $product))
            ->assertRedirect();

        $this->assertFalse($product->refresh()->is_active);
    }

    public function test_staff_can_record_a_cash_investment_for_a_member(): void
    {
        [$group, $staff] = $this->memberWithWallet('treasurer');
        $member = $this->activeMember($group);
        $product = $this->product($group);
        $ledger = app(LedgerService::class);
        $cashBefore = $ledger->accountBalance($group, LedgerService::CASH);

        $response = $this->actingAs($staff)->post(route('investments.place'), [
            'member_id' => $member->id,
            'investment_product_id' => $product->id,
            'amount' => '10000',
            'start_date' => now()->toDateString(),
            'funding_source' => 'cash',
            'reference' => 'REC-1001',
        ]);

        $investment = Investment::withoutGlobalScopes()->firstOrFail();

        $response->assertRedirect(route('investments.show', $investment));
        $this->assertSame($member->id, $investment->member_id);
        $this->assertSame(1_000_000, $investment->principal);

        $ledger = app(LedgerService::class);
        $this->assertSame($cashBefore + 1_000_000, $ledger->accountBalance($group, LedgerService::CASH));
        $this->assertSame(1_000_000, $ledger->accountBalance($group, LedgerService::INVESTMENT_LIABILITY));
        $this->assertSame(0, (int) Wallet::withoutGlobalScopes()->where('member_id', $member->id)->sum('balance'));
    }

    public function test_a_member_can_open_the_investment_portal_pages(): void
    {
        [$group, $user, $member] = $this->memberWithWallet();
        $product = $this->product($group);
        $investment = app(InvestmentService::class)->open(
            member: $member,
            product: $product,
            amount: 1_000_000,
        );

        $this->actingAs($user)
            ->get(route('portal.investments.index'))
            ->assertOk()
            ->assertSee('My investments')
            ->assertSee($product->name);

        $this->actingAs($user)
            ->get(route('portal.investments.create'))
            ->assertOk()
            ->assertSee('Place an investment')
            ->assertSee('Preview returns');

        $this->actingAs($user)
            ->get(route('portal.investments.show', $investment))
            ->assertOk()
            ->assertSee($investment->reference_no)
            ->assertSee('Interest schedule');

        $this->actingAs($user)
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('My investments');
    }

    public function test_staff_can_open_the_investment_management_pages(): void
    {
        [$group, $staff] = $this->memberWithWallet('treasurer');
        $member = $this->activeMember($group);
        $product = $this->product($group);
        $investment = app(InvestmentService::class)->open(
            member: $member,
            product: $product,
            amount: 1_000_000,
            actor: $staff,
            fundingSource: 'cash',
            reference: 'TEST-CASH-1',
        );

        $this->actingAs($staff)
            ->get(route('investments.index'))
            ->assertOk()
            ->assertSee('Investment products')
            ->assertSee($investment->reference_no);

        $this->actingAs($staff)
            ->get(route('investments.show', $investment))
            ->assertOk()
            ->assertSee($investment->reference_no)
            ->assertSee('Interest schedule');
    }

    public function test_a_member_cannot_open_the_staff_investment_screens(): void
    {
        [, $user] = $this->memberWithWallet();

        $this->actingAs($user)
            ->get(route('investments.index'))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Group, 1: User, 2: Member, 3: Wallet}
     */
    private function memberWithWallet(string $roleKey = 'member', array $overrides = []): array
    {
        $group = Group::factory()->create();

        $role = Role::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'key' => $roleKey,
            'name' => config('chama.roles.'.($roleKey === 'member' ? 'member' : $roleKey), ucfirst($roleKey)),
            'permissions' => config('chama.role_permissions.'.$roleKey, []),
            'is_system' => true,
        ]);

        $user = User::factory()->create();
        $member = Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create(array_merge([
                'role_id' => $role->id,
                'status' => 'active',
            ], $overrides));

        app(WalletService::class)->credit(
            member: $member,
            amount: 5_000_000,
            type: 'wallet_top_up',
            counterAccountCode: LedgerService::CASH,
            reference: 'SEED-'.$member->id,
        );

        $wallet = Wallet::withoutGlobalScopes()->where('member_id', $member->id)->firstOrFail();

        return [$group, $user, $member, $wallet];
    }

    private function activeMember(Group $group): Member
    {
        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'member'],
            [
                'name' => 'Member',
                'permissions' => config('chama.role_permissions.member', []),
                'is_system' => true,
            ]
        );

        return Member::factory()
            ->forGroup($group)
            ->create(['role_id' => $role->id, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(Group $group, array $overrides = []): InvestmentProduct
    {
        return InvestmentProduct::withoutGlobalScopes()->create(array_merge([
            'group_id' => $group->id,
            'name' => 'Fixed deposit',
            'code' => 'FIX-'.Str::upper(Str::random(6)),
            'type' => InvestmentProduct::TYPE_FIXED_TERM,
            'description' => 'A simple fixed-term investment.',
            'interest_rate_bps' => 1200,
            'interest_period' => InvestmentProduct::PERIOD_MONTHLY,
            'interest_method' => InvestmentProduct::METHOD_SIMPLE,
            'duration_months' => 12,
            'min_amount' => 100_000,
            'max_amount' => null,
            'early_withdrawal_allowed' => false,
            'early_withdrawal_penalty_bps' => 0,
            'is_active' => true,
            'terms' => null,
        ], $overrides));
    }
}

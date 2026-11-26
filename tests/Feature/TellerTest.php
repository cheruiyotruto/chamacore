<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\TellerEntry;
use App\Models\TellerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TellerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teller_can_open_a_till_and_see_the_desk(): void
    {
        [$group, $teller] = $this->userWithRole('teller');

        $this->actingAs($teller)
            ->get(route('teller.index'))
            ->assertOk()
            ->assertSee('Teller desk')
            ->assertSee('Open your till');

        $this->actingAs($teller)
            ->post(route('teller.sessions.open'), [
                'opening_float' => '1000',
                'notes' => 'Morning shift',
            ])
            ->assertRedirect(route('teller.index'))
            ->assertSessionHas('status');

        $session = TellerSession::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($group->id, $session->group_id);
        $this->assertSame($teller->id, $session->user_id);
        $this->assertSame(TellerSession::STATUS_OPEN, $session->status);
        $this->assertSame(100_000, $session->opening_float);
    }

    public function test_a_cash_savings_deposit_posts_an_entry_and_prints_a_receipt(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '500',
        ]);

        $response = $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_deposit',
            'savings_account_id' => $account->id,
            'amount' => '250',
            'method' => 'cash',
            'reference' => 'DEP-001',
            'narration' => 'Weekly savings',
            'idempotency_key' => 'test-deposit-1',
        ]);

        $entry = TellerEntry::withoutGlobalScopes()->firstOrFail();

        $response->assertRedirect(route('teller.entries.receipt', $entry));

        $this->assertSame('savings_deposit', $entry->category);
        $this->assertSame(TellerEntry::DIRECTION_IN, $entry->direction);
        $this->assertSame(25_000, $entry->amount);
        $this->assertSame('cash', $entry->method);
        $this->assertNotNull($entry->journal_entry_id);
        $this->assertStringStartsWith('RCP-', $entry->receipt_no);

        $this->assertSame(25_000, $account->refresh()->balance);

        $this->actingAs($teller)
            ->get(route('teller.entries.receipt', $entry))
            ->assertOk()
            ->assertSee($entry->receipt_no)
            ->assertSee($member->full_name)
            ->assertSee('Savings deposit');
    }

    public function test_closing_a_till_calculates_expected_cash_and_variance(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '1000',
        ]);

        $session = TellerSession::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_deposit',
            'savings_account_id' => $account->id,
            'amount' => '500',
            'method' => 'cash',
            'idempotency_key' => 'test-close-deposit',
        ]);

        $this->actingAs($teller)
            ->post(route('teller.sessions.close', $session), [
                'counted_cash' => '1450',
                'closing_notes' => 'Drawer short by 50',
            ])
            ->assertRedirect(route('teller.sessions.show', $session));

        $session->refresh();

        $this->assertSame(TellerSession::STATUS_CLOSED, $session->status);
        $this->assertSame(150_000, $session->expected_cash);
        $this->assertSame(145_000, $session->counted_cash);
        $this->assertSame(-5_000, $session->variance);
        $this->assertNotNull($session->closed_at);
    }

    public function test_a_member_cannot_use_the_teller_desk(): void
    {
        [$group] = $this->userWithRole('teller');
        [, $memberUser] = $this->activeMember($group);

        $this->actingAs($memberUser)
            ->get(route('teller.index'))
            ->assertForbidden();
    }

    public function test_a_teller_cannot_post_a_transaction_without_an_open_till(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);

        $this->actingAs($teller)
            ->from(route('teller.member', $member))
            ->post(route('teller.entries.store'), [
                'member_id' => $member->id,
                'category' => 'wallet_deposit',
                'amount' => '100',
                'method' => 'cash',
            ])
            ->assertRedirect(route('teller.member', $member))
            ->assertSessionHasErrors('teller');

        $this->assertSame(0, TellerEntry::withoutGlobalScopes()->count());
    }

    public function test_a_cheque_deposit_is_captured_as_pending_without_posting_to_the_ledger(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '500',
        ]);

        $response = $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_deposit',
            'savings_account_id' => $account->id,
            'amount' => '250',
            'method' => 'cheque',
            'cheque_number' => '000123',
            'bank_name' => 'Equity Bank',
            'branch' => 'Nairobi',
            'drawer_name' => 'Jane Doe',
            'cheque_date' => '2026-11-20',
            'payee_name' => $group->name,
            'idempotency_key' => 'cheque-capture-1',
        ]);

        $entry = TellerEntry::withoutGlobalScopes()->firstOrFail();

        $response->assertRedirect(route('teller.entries.receipt', $entry));

        $this->assertSame(TellerEntry::STATUS_PENDING, $entry->status);
        $this->assertTrue($entry->isCheque());
        $this->assertSame('000123', $entry->cheque_details['cheque_number']);
        $this->assertSame('Equity Bank', $entry->cheque_details['bank_name']);
        $this->assertSame('000123', $entry->reference);
        $this->assertNull($entry->journal_entry_id);
        $this->assertNull($entry->source_id);
        $this->assertSame(0, $entry->charge_amount);
        $this->assertSame(0, $account->refresh()->balance);

        $this->assertSame(1, AuditLog::where('action', 'teller.entry.cheque_captured')
            ->where('new_values->teller_entry_id', $entry->id)
            ->count());

        $this->actingAs($teller)
            ->get(route('teller.entries.receipt', $entry))
            ->assertOk()
            ->assertSee('held pending clearance')
            ->assertSee('No ledger entry has been posted');
    }

    public function test_clearing_a_cheque_posts_the_deposit_to_the_ledger_even_after_the_till_closes(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '500',
        ]);

        $entry = $this->captureSavingsCheque($group, $teller, $member, $account);
        $session = $entry->session;

        $this->actingAs($teller)->post(route('teller.sessions.close', $session), [
            'counted_cash' => '500',
        ])->assertRedirect(route('teller.sessions.show', $session));

        $this->actingAs($teller)->post(route('teller.cheques.clear', $entry), [
            'clearance_notes' => 'Bank confirmed funds',
        ])->assertRedirect(route('teller.cheques.index'));

        $entry->refresh();

        $this->assertSame(TellerEntry::STATUS_CLEARED, $entry->status);
        $this->assertNotNull($entry->journal_entry_id);
        $this->assertNotNull($entry->source_id);
        $this->assertSame($teller->id, $entry->cleared_by);
        $this->assertNotNull($entry->cleared_at);
        $this->assertSame('Bank confirmed funds', $entry->clearance_notes);
        $this->assertSame(25_000, $account->refresh()->balance);

        $this->assertSame(1, AuditLog::where('action', 'teller.cheque.cleared')
            ->where('new_values->teller_entry_id', $entry->id)
            ->count());
    }

    public function test_bouncing_a_cheque_does_not_post_to_the_ledger(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '500',
        ]);

        $entry = $this->captureSavingsCheque($group, $teller, $member, $account);

        $this->actingAs($teller)->post(route('teller.cheques.bounce', $entry), [
            'clearance_notes' => 'Insufficient funds',
        ])->assertRedirect(route('teller.cheques.index'));

        $entry->refresh();

        $this->assertSame(TellerEntry::STATUS_BOUNCED, $entry->status);
        $this->assertNull($entry->journal_entry_id);
        $this->assertNull($entry->source_id);
        $this->assertSame($teller->id, $entry->bounced_by);
        $this->assertNotNull($entry->bounced_at);
        $this->assertSame(0, $account->refresh()->balance);

        $this->assertSame(1, AuditLog::where('action', 'teller.cheque.bounced')
            ->where('new_values->teller_entry_id', $entry->id)
            ->count());
    }

    public function test_a_cheque_cannot_be_captured_for_an_outbound_transaction(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [, $memberUser] = $this->activeMember($group);
        $member = Member::withoutGlobalScopes()->where('user_id', $memberUser->id)->firstOrFail();

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '500',
        ]);

        $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'wallet_withdrawal',
            'amount' => '100',
            'method' => 'cheque',
            'cheque_number' => '000999',
            'bank_name' => 'Equity Bank',
            'cheque_date' => '2026-11-20',
        ])->assertSessionHasErrors('teller');

        $this->assertSame(0, TellerEntry::withoutGlobalScopes()->count());
    }

    public function test_a_cheque_cannot_be_resolved_twice(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '500',
        ]);

        $entry = $this->captureSavingsCheque($group, $teller, $member, $account);

        $this->actingAs($teller)
            ->from(route('teller.cheques.index'))
            ->post(route('teller.cheques.clear', $entry))
            ->assertRedirect(route('teller.cheques.index'));

        $this->actingAs($teller)
            ->from(route('teller.cheques.index'))
            ->post(route('teller.cheques.bounce', $entry))
            ->assertRedirect(route('teller.cheques.index'))
            ->assertSessionHasErrors('teller');

        $this->assertSame(TellerEntry::STATUS_CLEARED, $entry->refresh()->status);
    }

    public function test_only_tellers_can_clear_cheques(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member, $memberUser] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '500',
        ]);

        $entry = $this->captureSavingsCheque($group, $teller, $member, $account);

        $this->actingAs($memberUser)
            ->post(route('teller.cheques.clear', $entry))
            ->assertForbidden();

        $this->assertSame(TellerEntry::STATUS_PENDING, $entry->refresh()->status);
    }

    public function test_the_cheque_clearance_list_shows_pending_cheques(): void
    {
        [$group, $teller] = $this->userWithRole('teller');
        [$member] = $this->activeMember($group);
        $account = $this->savingsAccount($group, $member);

        $this->actingAs($teller)->post(route('teller.sessions.open'), [
            'opening_float' => '500',
        ]);

        $entry = $this->captureSavingsCheque($group, $teller, $member, $account);

        $this->actingAs($teller)
            ->get(route('teller.cheques.index'))
            ->assertOk()
            ->assertSee('Cheque clearance')
            ->assertSee($entry->receipt_no)
            ->assertSee('Pending clearance')
            ->assertSee('000123');
    }

    private function captureSavingsCheque(
        Group $group,
        User $teller,
        Member $member,
        SavingsAccount $account
    ): TellerEntry {
        $this->actingAs($teller)->post(route('teller.entries.store'), [
            'member_id' => $member->id,
            'category' => 'savings_deposit',
            'savings_account_id' => $account->id,
            'amount' => '250',
            'method' => 'cheque',
            'cheque_number' => '000123',
            'bank_name' => 'Equity Bank',
            'branch' => 'Nairobi',
            'cheque_date' => '2026-11-20',
            'payee_name' => $group->name,
            'idempotency_key' => 'cheque-test-'.$member->id,
        ])->assertRedirect();

        return TellerEntry::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /**
     * @return array{0: SavingsAccount, 1: SavingsProduct}
     */
    private function savingsAccount(Group $group, Member $member): SavingsAccount
    {
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

        return SavingsAccount::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'savings_product_id' => $product->id,
            'account_no' => 'SA-TEST-'.$member->id,
            'balance' => 0,
            'available_balance' => 0,
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
            ]);

        return [$member, $user];
    }
}

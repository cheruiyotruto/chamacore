<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_clean_ledgers(): void
    {
        $group = Group::factory()->create();
        app(LedgerService::class)->bootstrap($group);

        $this->artisan('chama:reconcile-ledgers', ['--strict' => true])
            ->expectsOutputToContain('reconcile')
            ->assertSuccessful();
    }

    public function test_strict_mode_fails_when_savings_do_not_match_the_ledger(): void
    {
        $group = Group::factory()->create();
        app(LedgerService::class)->bootstrap($group);

        $product = SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Voluntary',
            'code' => 'VOL',
            'type' => 'voluntary',
            'interest_rate_bps' => 0,
            'interest_period' => 'annual',
            'min_balance' => 0,
            'allows_withdrawal' => true,
            'qualifies_for_loan' => true,
            'is_active' => true,
        ]);

        $member = \App\Models\Member::factory()->forGroup($group)->create();

        SavingsAccount::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'savings_product_id' => $product->id,
            'account_no' => 'SA-TEST-001',
            'balance' => 50_000,
            'available_balance' => 50_000,
            'status' => 'active',
            'opened_at' => now(),
        ]);

        $this->artisan('chama:reconcile-ledgers', ['--strict' => true])
            ->assertFailed();
    }
}

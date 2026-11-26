<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_posted_journal_is_balanced_and_updates_account_balances(): void
    {
        $group = Group::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->bootstrap($group);

        $entry = $ledger->post($group, 'Opening cash', [
            ['code' => LedgerService::CASH, 'debit' => 150_000],
            ['code' => LedgerService::GROUP_EQUITY, 'credit' => 150_000],
        ]);

        $this->assertSame(150_000, (int) $entry->lines()->sum('debit'));
        $this->assertSame(150_000, (int) $entry->lines()->sum('credit'));
        $this->assertSame(150_000, $ledger->accountBalance($group, LedgerService::CASH));
        $this->assertSame(150_000, $ledger->accountBalance($group, LedgerService::GROUP_EQUITY));
        $this->assertSame($ledger->totalDebits($group), $ledger->totalCredits($group));

        $trial = collect($ledger->trialBalance($group));
        $this->assertSame(
            (int) $trial->sum('debit'),
            (int) $trial->sum('credit')
        );
    }

    public function test_an_unbalanced_journal_is_rejected(): void
    {
        $group = Group::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->bootstrap($group);

        $this->expectException(InvalidArgumentException::class);

        $ledger->post($group, 'Broken journal', [
            ['code' => LedgerService::CASH, 'debit' => 100_000],
            ['code' => LedgerService::GROUP_EQUITY, 'credit' => 90_000],
        ]);
    }

    public function test_a_reversal_removes_the_original_effect(): void
    {
        $group = Group::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->bootstrap($group);

        $entry = $ledger->post($group, 'Temporary receipt', [
            ['code' => LedgerService::CASH, 'debit' => 80_000],
            ['code' => LedgerService::OTHER_INCOME, 'credit' => 80_000],
        ]);

        $reversal = $ledger->reverse($group, $entry, 'Duplicate receipt');

        $this->assertSame(0, $ledger->accountBalance($group, LedgerService::CASH));
        $this->assertSame($entry->id, $reversal->reversal_of_id);
        $this->assertSame('reversed', $entry->refresh()->status);
        $this->assertSame($ledger->totalDebits($group), $ledger->totalCredits($group));
    }
}

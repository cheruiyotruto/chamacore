<?php

namespace App\Services;

use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerLine;
use App\Models\Member;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerService
{
    public const CASH = '1000';
    public const BANK = '1010';
    public const LOANS_RECEIVABLE = '1100';
    public const INTEREST_RECEIVABLE = '1110';
    public const FEES_RECEIVABLE = '1200';
    public const SAVINGS_LIABILITY = '2000';
    public const WALLET_LIABILITY = '2020';
    public const INVESTMENT_LIABILITY = '2030';
    public const BILL_PAYABLE = '2040';
    public const PAYOUT_CLEARING = '2050';
    public const WELFARE_FUND = '2100';
    public const MGR_FUNDS = '2200';
    public const SHARE_CAPITAL = '3010';
    public const GROUP_EQUITY = '3000';
    public const RETAINED_EARNINGS = '3020';
    public const INTEREST_INCOME = '4000';
    public const PENALTY_INCOME = '4010';
    public const FINES_INCOME = '4020';
    public const MEMBERSHIP_FEE_INCOME = '4030';
    public const OTHER_INCOME = '4040';
    public const TELLER_CHARGE_INCOME = '4050';
    public const TRANSACTION_CHARGE_INCOME = '4060';
    public const PLATFORM_CHARGE_INCOME = '4070';
    public const SAVINGS_INTEREST_EXPENSE = '5000';
    public const OPERATING_EXPENSE = '5010';
    public const WELFARE_EXPENSE = '5020';
    public const LOAN_WRITE_OFF = '5030';
    public const INVESTMENT_INTEREST_EXPENSE = '5040';

    /**
     * @return array<string, array{name:string,type:string,normal:string}>
     */
    public function chart(): array
    {
        return [
            self::CASH => ['name' => 'Cash on hand', 'type' => 'asset', 'normal' => 'debit'],
            self::BANK => ['name' => 'Bank account', 'type' => 'asset', 'normal' => 'debit'],
            self::LOANS_RECEIVABLE => ['name' => 'Loans receivable (principal)', 'type' => 'asset', 'normal' => 'debit'],
            self::INTEREST_RECEIVABLE => ['name' => 'Interest receivable', 'type' => 'asset', 'normal' => 'debit'],
            self::FEES_RECEIVABLE => ['name' => 'Fees and penalties receivable', 'type' => 'asset', 'normal' => 'debit'],
            self::SAVINGS_LIABILITY => ['name' => 'Member savings', 'type' => 'liability', 'normal' => 'credit'],
            self::WALLET_LIABILITY => ['name' => 'Member wallet balances', 'type' => 'liability', 'normal' => 'credit'],
            self::INVESTMENT_LIABILITY => ['name' => 'Member investments', 'type' => 'liability', 'normal' => 'credit'],
            self::BILL_PAYABLE => ['name' => 'Customer bill collections payable', 'type' => 'liability', 'normal' => 'credit'],
            self::PAYOUT_CLEARING => ['name' => 'Bank transfers and payouts clearing', 'type' => 'liability', 'normal' => 'credit'],
            self::WELFARE_FUND => ['name' => 'Welfare fund', 'type' => 'liability', 'normal' => 'credit'],
            self::MGR_FUNDS => ['name' => 'Merry-go-round funds held', 'type' => 'liability', 'normal' => 'credit'],
            self::SHARE_CAPITAL => ['name' => 'Member share capital', 'type' => 'equity', 'normal' => 'credit'],
            self::GROUP_EQUITY => ['name' => 'Group equity', 'type' => 'equity', 'normal' => 'credit'],
            self::RETAINED_EARNINGS => ['name' => 'Retained earnings', 'type' => 'equity', 'normal' => 'credit'],
            self::INTEREST_INCOME => ['name' => 'Interest income', 'type' => 'income', 'normal' => 'credit'],
            self::PENALTY_INCOME => ['name' => 'Loan penalty income', 'type' => 'income', 'normal' => 'credit'],
            self::FINES_INCOME => ['name' => 'Fines income', 'type' => 'income', 'normal' => 'credit'],
            self::MEMBERSHIP_FEE_INCOME => ['name' => 'Membership fee income', 'type' => 'income', 'normal' => 'credit'],
            self::OTHER_INCOME => ['name' => 'Other income', 'type' => 'income', 'normal' => 'credit'],
            self::TELLER_CHARGE_INCOME => ['name' => 'Teller charges income', 'type' => 'income', 'normal' => 'credit'],
            self::TRANSACTION_CHARGE_INCOME => ['name' => 'Other transaction charges income', 'type' => 'income', 'normal' => 'credit'],
            self::PLATFORM_CHARGE_INCOME => ['name' => 'Platform transaction charges (managed groups)', 'type' => 'income', 'normal' => 'credit'],
            self::SAVINGS_INTEREST_EXPENSE => ['name' => 'Savings interest expense', 'type' => 'expense', 'normal' => 'debit'],
            self::OPERATING_EXPENSE => ['name' => 'Operating expenses', 'type' => 'expense', 'normal' => 'debit'],
            self::WELFARE_EXPENSE => ['name' => 'Welfare disbursements', 'type' => 'expense', 'normal' => 'debit'],
            self::LOAN_WRITE_OFF => ['name' => 'Loan write-offs', 'type' => 'expense', 'normal' => 'debit'],
            self::INVESTMENT_INTEREST_EXPENSE => ['name' => 'Investment interest expense', 'type' => 'expense', 'normal' => 'debit'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function chartIncomeAccounts(): array
    {
        return collect($this->chart())
            ->filter(fn (array $definition) => $definition['type'] === 'income')
            ->map(fn (array $definition) => $definition['name'])
            ->all();
    }

    public function bootstrap(Group $group): void
    {
        DB::transaction(function () use ($group) {
            foreach ($this->chart() as $code => $definition) {
                LedgerAccount::withoutGlobalScopes()->firstOrCreate(
                    ['group_id' => $group->id, 'code' => $code],
                    [
                        'name' => $definition['name'],
                        'type' => $definition['type'],
                        'normal_balance' => $definition['normal'],
                        'is_system' => true,
                        'is_active' => true,
                    ]
                );
            }
        });
    }

    public function account(Group $group, string $code): LedgerAccount
    {
        $account = LedgerAccount::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('code', $code)
            ->first();

        if (! $account) {
            $this->bootstrap($group);
            $account = LedgerAccount::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->where('code', $code)
                ->first();
        }

        if (! $account) {
            throw new InvalidArgumentException("Ledger account [{$code}] is not configured for this group.");
        }

        return $account;
    }

    /**
     * Income accounts that transaction charges can credit.
     *
     * @return array<string, string>
     */
    public function incomeAccounts(Group $group): array
    {
        $this->bootstrap($group);

        return LedgerAccount::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('type', 'income')
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * Post a balanced double-entry journal.
     *
     * Each line: ['code' => '1000', 'debit' => 100, 'credit' => 0, 'member_id' => null, 'description' => '']
     *
     * @param array<int, array{code:string, debit?:int, credit?:int, member_id?:int|null, description?:string}> $lines
     */
    public function post(
        Group $group,
        string $description,
        array $lines,
        CarbonInterface|string|null $entryDate = null,
        ?Model $source = null,
        ?string $reference = null
    ): JournalEntry {
        return DB::transaction(function () use ($group, $description, $lines, $entryDate, $source) {
            if (count($lines) < 2) {
                throw new InvalidArgumentException('A journal entry requires at least two lines.');
            }

            $totalDebit = 0;
            $totalCredit = 0;
            $resolved = [];

            foreach ($lines as $line) {
                $debit = (int) ($line['debit'] ?? 0);
                $credit = (int) ($line['credit'] ?? 0);

                if ($debit < 0 || $credit < 0) {
                    throw new InvalidArgumentException('Debit and credit amounts cannot be negative.');
                }

                if (($debit === 0 && $credit === 0) || ($debit > 0 && $credit > 0)) {
                    throw new InvalidArgumentException('Each journal line must contain exactly one non-zero side.');
                }

                $account = $this->account($group, $line['code']);

                $totalDebit += $debit;
                $totalCredit += $credit;

                $resolved[] = [
                    'ledger_account_id' => $account->id,
                    'member_id' => $line['member_id'] ?? null,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => $line['description'] ?? $description,
                ];
            }

            if ($totalDebit !== $totalCredit) {
                throw new InvalidArgumentException(
                    "Unbalanced journal: debits {$totalDebit} do not equal credits {$totalCredit}."
                );
            }

            if ($totalDebit === 0) {
                throw new InvalidArgumentException('Journal total cannot be zero.');
            }

            // Serialise entry numbering per group.
            DB::table('groups')->where('id', $group->id)->lockForUpdate()->first();

            $date = $entryDate ? \Carbon\CarbonImmutable::parse($entryDate) : \Carbon\CarbonImmutable::now();
            $sequence = JournalEntry::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereYear('entry_date', $date->year)
                ->count() + 1;

            $entry = JournalEntry::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'entry_no' => 'JV-'.$date->format('Y').'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'entry_date' => $date->toDateString(),
                'description' => $description,
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
                'status' => 'posted',
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);

            foreach ($resolved as $line) {
                LedgerLine::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => $line['ledger_account_id'],
                    'member_id' => $line['member_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'],
                ]);
            }

            if ($entry->lines()->sum('debit') !== $entry->lines()->sum('credit')) {
                throw new \RuntimeException('Posted journal is unbalanced; transaction rolled back.');
            }

            AuditService::log('journal.posted', [
                'journal_entry_id' => $entry->id,
                'entry_no' => $entry->entry_no,
                'description' => $description,
                'amount' => $totalDebit,
            ], $group);

            return $entry;
        });
    }

    public function reverse(Group $group, JournalEntry $entry, string $reason): JournalEntry
    {
        if ($entry->group_id !== $group->id) {
            throw new InvalidArgumentException('Journal entry does not belong to this group.');
        }

        if ($entry->status === 'reversed') {
            throw new InvalidArgumentException('This journal entry has already been reversed.');
        }

        $originalLines = $entry->lines()->get();

        $reversal = DB::transaction(function () use ($group, $entry, $reason, $originalLines) {
            $lines = $originalLines->map(fn (LedgerLine $line) => [
                'code' => $line->account->code,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'member_id' => $line->member_id,
                'description' => 'Reversal: '.$line->description,
            ])->all();

            $reversal = $this->post(
                $group,
                'Reversal of '.$entry->entry_no.': '.$reason,
                $lines,
                now(),
                $entry
            );

            $reversal->update(['reversal_of_id' => $entry->id]);
            $entry->update(['status' => 'reversed']);

            AuditService::log('journal.reversed', [
                'original_entry' => $entry->entry_no,
                'reversal_entry' => $reversal->entry_no,
                'reason' => $reason,
            ], $group);

            return $reversal;
        });

        return $reversal->refresh();
    }

    public function accountBalance(Group $group, string $code, ?CarbonInterface $to = null): int
    {
        $account = $this->account($group, $code);

        $query = LedgerLine::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('ledger_account_id', $account->id)
            ->when($to, fn ($q) => $q->whereHas('journalEntry', fn ($j) => $j->whereDate('entry_date', '<=', $to)));

        $debit = (int) (clone $query)->sum('debit');
        $credit = (int) (clone $query)->sum('credit');

        return $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    /**
     * @return array<int, array{account:LedgerAccount, debit:int, credit:int, balance:int}>
     */
    public function trialBalance(Group $group, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $accounts = LedgerAccount::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->orderBy('code')
            ->get();

        $totals = LedgerLine::withoutGlobalScopes()
            ->selectRaw('ledger_account_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->where('group_id', $group->id)
            ->when($from, fn ($q) => $q->whereHas('journalEntry', fn ($j) => $j->whereDate('entry_date', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('journalEntry', fn ($j) => $j->whereDate('entry_date', '<=', $to)))
            ->groupBy('ledger_account_id')
            ->get()
            ->keyBy('ledger_account_id');

        return $accounts->map(function (LedgerAccount $account) use ($totals) {
            $row = $totals->get($account->id);
            $debit = (int) ($row->total_debit ?? 0);
            $credit = (int) ($row->total_credit ?? 0);
            $balance = $account->isDebitNormal() ? $debit - $credit : $credit - $debit;

            return ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'balance' => $balance];
        })->all();
    }

    public function totalDebits(Group $group, ?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        return (int) LedgerLine::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->when($from, fn ($q) => $q->whereHas('journalEntry', fn ($j) => $j->whereDate('entry_date', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('journalEntry', fn ($j) => $j->whereDate('entry_date', '<=', $to)))
            ->sum('debit');
    }

    public function totalCredits(Group $group, ?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        return (int) LedgerLine::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->when($from, fn ($q) => $q->whereHas('journalEntry', fn ($j) => $j->whereDate('entry_date', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('journalEntry', fn ($j) => $j->whereDate('entry_date', '<=', $to)))
            ->sum('credit');
    }
}

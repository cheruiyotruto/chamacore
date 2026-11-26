<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\PaymentChargeSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PaymentChargeService $charges,
    ) {
    }

    public function ensureWallet(Member $member): Wallet
    {
        return Wallet::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $member->group_id, 'member_id' => $member->id],
            ['balance' => 0, 'available_balance' => 0, 'status' => 'active']
        );
    }

    /**
     * Credit the member's wallet, debiting the supplied counter account.
     */
    public function credit(
        Member $member,
        int $amount,
        string $type,
        string $counterAccountCode,
        string $reference,
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?Model $source = null
    ): WalletTransaction {
        return $this->post(
            member: $member,
            amount: $amount,
            direction: 'credit',
            type: $type,
            counterAccountCode: $counterAccountCode,
            reference: $reference,
            idempotencyKey: $idempotencyKey,
            description: $description,
            source: $source
        );
    }

    /**
     * Debit the member's wallet, crediting the supplied counter account.
     */
    public function debit(
        Member $member,
        int $amount,
        string $type,
        string $counterAccountCode,
        string $reference,
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?Model $source = null
    ): WalletTransaction {
        return $this->post(
            member: $member,
            amount: $amount,
            direction: 'debit',
            type: $type,
            counterAccountCode: $counterAccountCode,
            reference: $reference,
            idempotencyKey: $idempotencyKey,
            description: $description,
            source: $source
        );
    }

    private function post(
        Member $member,
        int $amount,
        string $direction,
        string $type,
        string $counterAccountCode,
        string $reference,
        ?string $idempotencyKey,
        ?string $description,
        ?Model $source
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new BusinessRuleException('Wallet transaction amount must be greater than zero.');
        }

        return DB::transaction(function () use (
            $member, $amount, $direction, $type, $counterAccountCode,
            $reference, $idempotencyKey, $description, $source
        ) {
            $group = Group::findOrFail($member->group_id);

            if ($idempotencyKey) {
                $existing = WalletTransaction::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $wallet = Wallet::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->where('member_id', $member->id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = $this->ensureWallet($member);
                $wallet = Wallet::withoutGlobalScopes()->lockForUpdate()->findOrFail($wallet->id);
            }

            if ($wallet->status !== 'active') {
                throw new BusinessRuleException('This wallet is not active.');
            }

            if ($direction === 'debit' && $wallet->available_balance < $amount) {
                throw new BusinessRuleException('Insufficient wallet balance.');
            }

            $newBalance = $direction === 'credit'
                ? $wallet->balance + $amount
                : $wallet->balance - $amount;

            if ($newBalance < 0) {
                throw new BusinessRuleException('Wallet balance cannot become negative.');
            }

            $ledgerLines = $direction === 'credit'
                ? [
                    ['code' => $counterAccountCode, 'debit' => $amount, 'member_id' => $member->id],
                    ['code' => LedgerService::WALLET_LIABILITY, 'credit' => $amount, 'member_id' => $member->id],
                ]
                : [
                    ['code' => LedgerService::WALLET_LIABILITY, 'debit' => $amount, 'member_id' => $member->id],
                    ['code' => $counterAccountCode, 'credit' => $amount, 'member_id' => $member->id],
                ];

            $journal = $this->ledger->post(
                $group,
                $description ?: ucfirst(str_replace('_', ' ', $type)),
                $ledgerLines,
                now(),
                $source,
                $reference
            );

            $transaction = WalletTransaction::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'wallet_id' => $wallet->id,
                'member_id' => $member->id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'status' => 'completed',
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'related_type' => $source ? $source::class : null,
                'related_id' => $source?->getKey(),
                'journal_entry_id' => $journal->id,
                'completed_at' => now(),
            ]);

            $wallet->update([
                'balance' => $newBalance,
                'available_balance' => $direction === 'credit'
                    ? $wallet->available_balance + $amount
                    : $wallet->available_balance - $amount,
            ]);

            return $transaction;
        });
    }

    public function transfer(
        Member $from,
        Member $to,
        int $amount,
        string $reference,
        ?string $idempotencyKey = null
    ): array {
        if ($from->group_id !== $to->group_id) {
            throw new BusinessRuleException('Wallet transfers are only permitted within the same group.');
        }

        if ($from->id === $to->id) {
            throw new BusinessRuleException('You cannot transfer to the same wallet.');
        }

        if ($amount <= 0) {
            throw new BusinessRuleException('Transfer amount must be greater than zero.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $reference, $idempotencyKey) {
            $group = Group::findOrFail($from->group_id);
            $wallets = Wallet::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereIn('member_id', [$from->id, $to->id])
                ->lockForUpdate()
                ->get()
                ->keyBy('member_id');

            $sourceWallet = $wallets->get($from->id);
            $destinationWallet = $wallets->get($to->id);

            if (! $sourceWallet || ! $destinationWallet) {
                throw new BusinessRuleException('Both members must have an active wallet.');
            }

            $quote = $this->charges->quote($group, $amount, [PaymentChargeSettings::ALL_PAYMENTS]);
            $debitAmount = (int) $quote['payer_net_out'];
            $creditAmount = (int) $quote['net_to_payee'];

            if ($sourceWallet->available_balance < $debitAmount) {
                throw new BusinessRuleException('Insufficient wallet balance.');
            }

            $journal = $this->charges->postPaymentJournal($group, $quote, [
                'counter_code' => LedgerService::WALLET_LIABILITY,
                'payer' => $from,
                'payee' => $to,
                'payee_account_code' => LedgerService::WALLET_LIABILITY,
                'reference' => $reference,
                'description' => 'Internal wallet transfer',
            ]);

            $debit = $this->recordJournalMovement(
                $from,
                $debitAmount,
                'debit',
                'internal_transfer',
                $reference,
                $journal,
                'Transfer to '.$to->full_name
            );
            $credit = $this->recordJournalMovement(
                $to,
                $creditAmount,
                'credit',
                'internal_transfer',
                $reference,
                $journal,
                'Transfer from '.$from->full_name
            );

            if ($idempotencyKey && $debit->idempotency_key === null) {
                $debit->update(['idempotency_key' => $idempotencyKey]);
            }

            AuditService::log('wallet.transfer', [
                'from_member_id' => $from->id,
                'to_member_id' => $to->id,
                'amount' => $amount,
                'charge' => $quote['total_fee'] ?? 0,
                'reference' => $reference,
            ], $group);

            return ['debit' => $debit, 'credit' => $credit, 'journal' => $journal];
        });
    }

    /**
     * Update a wallet and record a transaction for a journal that another
     * service has already posted (for example a bill payment or transfer).
     * The amount must be the net movement for this member.
     */
    public function recordJournalMovement(
        Member $member,
        int $amount,
        string $direction,
        string $type,
        string $reference,
        JournalEntry $journal,
        ?string $description = null
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new BusinessRuleException('Wallet movement amount must be greater than zero.');
        }

        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new BusinessRuleException('Invalid wallet movement direction.');
        }

        return DB::transaction(function () use ($member, $amount, $direction, $type, $reference, $journal, $description) {
            $wallet = Wallet::withoutGlobalScopes()
                ->where('group_id', $member->group_id)
                ->where('member_id', $member->id)
                ->lockForUpdate()
                ->first() ?: $this->ensureWallet($member);

            $newBalance = $direction === 'credit'
                ? $wallet->balance + $amount
                : $wallet->balance - $amount;

            if ($newBalance < 0) {
                throw new BusinessRuleException('Insufficient wallet balance.');
            }

            $transaction = WalletTransaction::withoutGlobalScopes()->create([
                'group_id' => $member->group_id,
                'wallet_id' => $wallet->id,
                'member_id' => $member->id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'status' => 'completed',
                'reference' => $reference,
                'description' => $description ?: ucfirst(str_replace('_', ' ', $type)),
                'related_type' => $journal->source_type,
                'related_id' => $journal->source_id,
                'journal_entry_id' => $journal->id,
                'completed_at' => now(),
            ]);

            $wallet->update([
                'balance' => $newBalance,
                'available_balance' => $direction === 'credit'
                    ? $wallet->available_balance + $amount
                    : $wallet->available_balance - $amount,
            ]);

            return $transaction;
        });
    }

    public function reconcile(Group $group): array
    {
        $wallets = Wallet::withoutGlobalScopes()->where('group_id', $group->id)->get();
        $issues = [];

        foreach ($wallets as $wallet) {
            $transactionTotal = (int) WalletTransaction::withoutGlobalScopes()
                ->where('wallet_id', $wallet->id)
                ->where('status', 'completed')
                ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) as balance")
                ->value('balance');

            if ($transactionTotal !== $wallet->balance) {
                $issues[] = [
                    'wallet_id' => $wallet->id,
                    'member_id' => $wallet->member_id,
                    'wallet_balance' => $wallet->balance,
                    'transaction_total' => $transactionTotal,
                ];
            }
        }

        $ledgerBalance = $this->ledger->accountBalance($group, LedgerService::WALLET_LIABILITY);
        $walletTotal = (int) $wallets->sum('balance');

        if ($ledgerBalance !== $walletTotal) {
            $issues[] = [
                'type' => 'ledger_mismatch',
                'ledger_balance' => $ledgerBalance,
                'wallet_total' => $walletTotal,
            ];
        }

        return $issues;
    }
}

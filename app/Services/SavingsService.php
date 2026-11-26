<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SavingsService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function openAccount(Member $member, SavingsProduct $product): SavingsAccount
    {
        if ($product->group_id !== $member->group_id) {
            throw new BusinessRuleException('Savings product does not belong to the member\'s group.');
        }

        return SavingsAccount::withoutGlobalScopes()->firstOrCreate(
            ['member_id' => $member->id, 'savings_product_id' => $product->id],
            [
                'group_id' => $member->group_id,
                'account_no' => 'SA-'.str_pad((string) $member->group_id, 3, '0', STR_PAD_LEFT)
                    .'-'.str_pad((string) $member->id, 5, '0', STR_PAD_LEFT)
                    .'-'.$product->code,
                'balance' => 0,
                'available_balance' => 0,
                'pledged_amount' => 0,
                'status' => 'active',
                'opened_at' => now(),
            ]
        );
    }

    public function openDefaultAccounts(Member $member): void
    {
        $products = SavingsProduct::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('is_active', true)
            ->get();

        foreach ($products as $product) {
            $this->openAccount($member, $product);
        }
    }

    public function deposit(
        Member $member,
        SavingsAccount $account,
        int $amount,
        string $type = 'deposit',
        string $counterAccountCode = LedgerService::CASH,
        string $reference = '',
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?Model $source = null,
        bool $viaWallet = false,
        ?Member $payer = null
    ): SavingsTransaction {
        if ($amount <= 0) {
            throw new BusinessRuleException('Deposit amount must be greater than zero.');
        }

        if ($account->member_id !== $member->id || $account->group_id !== $member->group_id) {
            throw new BusinessRuleException('The savings account does not belong to this member.');
        }

        $payer = $payer ?? $member;

        if ($payer->group_id !== $member->group_id) {
            throw new BusinessRuleException('The payer and beneficiary must belong to the same group.');
        }

        if ($account->status !== 'active') {
            throw new BusinessRuleException('This savings account is not active.');
        }

        $transaction = DB::transaction(function () use (
            $member, $account, $amount, $type, $counterAccountCode,
            $reference, $idempotencyKey, $description, $source, $viaWallet, $payer
        ) {
            $group = Group::findOrFail($member->group_id);
            $account = SavingsAccount::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);

            if ($idempotencyKey) {
                $existing = SavingsTransaction::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $wallet = null;
            if ($viaWallet) {
                $wallet = Wallet::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->where('member_id', $payer->id)
                    ->lockForUpdate()
                    ->first();

                if (! $wallet || $wallet->available_balance < $amount) {
                    throw new BusinessRuleException('Insufficient wallet balance for this deposit.');
                }
            }

            $newBalance = $account->balance + $amount;
            $debitCode = $viaWallet ? LedgerService::WALLET_LIABILITY : $counterAccountCode;

            $journal = $this->ledger->post($group, $description ?: 'Savings '.str_replace('_', ' ', $type), [
                ['code' => $debitCode, 'debit' => $amount, 'member_id' => $payer->id],
                ['code' => LedgerService::SAVINGS_LIABILITY, 'credit' => $amount, 'member_id' => $member->id],
            ], now(), $source, $reference);

            $transaction = SavingsTransaction::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'savings_account_id' => $account->id,
                'member_id' => $member->id,
                'type' => $type,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference' => $reference,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
                'journal_entry_id' => $journal->id,
                'transaction_date' => now()->toDateString(),
                'created_by' => auth()->id(),
            ]);

            $account->update([
                'balance' => $newBalance,
                'available_balance' => $account->available_balance + $amount,
            ]);

            if ($wallet) {
                $walletBalanceAfter = $wallet->balance - $amount;
                $walletAvailableAfter = $wallet->available_balance - $amount;

                $wallet->update([
                    'balance' => $walletBalanceAfter,
                    'available_balance' => $walletAvailableAfter,
                ]);

                WalletTransaction::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'wallet_id' => $wallet->id,
                    'member_id' => $payer->id,
                    'type' => 'savings_deposit',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'balance_after' => $walletBalanceAfter,
                    'status' => 'completed',
                    'reference' => $reference ?: 'WALLET-'.$transaction->id,
                    'idempotency_key' => $idempotencyKey ? $idempotencyKey.'-wallet' : null,
                    'description' => 'Savings deposit',
                    'journal_entry_id' => $journal->id,
                    'completed_at' => now(),
                ]);
            }

            return $transaction;
        });

        if ($payer->id !== $member->id) {
            AuditService::log('savings.deposit.paid_on_behalf_of', [
                'savings_transaction_id' => $transaction->id,
                'payer_member_id' => $payer->id,
                'beneficiary_member_id' => $member->id,
                'amount' => $amount,
                'reference' => $reference,
            ], Group::withoutGlobalScopes()->findOrFail($member->group_id));
        }

        // Funding an invited guarantee is a follow-on effect of the deposit,
        // not part of the ledger transaction itself.
        app(LoanOriginationService::class)->fundAwaitingGuarantees($member);

        return $transaction;
    }

    public function withdraw(
        Member $member,
        SavingsAccount $account,
        int $amount,
        string $counterAccountCode = LedgerService::CASH,
        string $reference = '',
        ?string $idempotencyKey = null,
        ?string $description = null,
        bool $toWallet = false
    ): SavingsTransaction {
        if ($amount <= 0) {
            throw new BusinessRuleException('Withdrawal amount must be greater than zero.');
        }

        if ($account->member_id !== $member->id || $account->group_id !== $member->group_id) {
            throw new BusinessRuleException('The savings account does not belong to this member.');
        }

        return DB::transaction(function () use (
            $member, $account, $amount, $counterAccountCode,
            $reference, $idempotencyKey, $description, $toWallet
        ) {
            $group = Group::findOrFail($member->group_id);
            $account = SavingsAccount::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            $product = SavingsProduct::withoutGlobalScopes()->findOrFail($account->savings_product_id);

            if (! $product->allows_withdrawal) {
                throw new BusinessRuleException('This savings product does not allow withdrawals.');
            }

            if ($account->available_balance < $amount) {
                throw new BusinessRuleException('Insufficient available savings balance.');
            }

            $remaining = $account->balance - $amount;
            if ($remaining < $product->min_balance) {
                throw new BusinessRuleException(
                    'Withdrawal would breach the minimum balance of '.Money::format($product->min_balance).'.'
                );
            }

            $wallet = null;
            if ($toWallet) {
                $wallet = Wallet::withoutGlobalScopes()->firstOrCreate(
                    ['group_id' => $group->id, 'member_id' => $member->id],
                    ['balance' => 0, 'available_balance' => 0, 'status' => 'active']
                );
                $wallet = Wallet::withoutGlobalScopes()->lockForUpdate()->findOrFail($wallet->id);
            }

            $creditCode = $toWallet ? LedgerService::WALLET_LIABILITY : $counterAccountCode;

            $journal = $this->ledger->post($group, $description ?: 'Savings withdrawal', [
                ['code' => LedgerService::SAVINGS_LIABILITY, 'debit' => $amount, 'member_id' => $member->id],
                ['code' => $creditCode, 'credit' => $amount, 'member_id' => $member->id],
            ], now(), null, $reference);

            $transaction = SavingsTransaction::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'savings_account_id' => $account->id,
                'member_id' => $member->id,
                'type' => 'withdrawal',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_after' => $remaining,
                'reference' => $reference,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
                'journal_entry_id' => $journal->id,
                'transaction_date' => now()->toDateString(),
                'created_by' => auth()->id(),
            ]);

            $account->update([
                'balance' => $remaining,
                'available_balance' => $account->available_balance - $amount,
            ]);

            if ($wallet) {
                $walletBalanceAfter = $wallet->balance + $amount;
                $walletAvailableAfter = $wallet->available_balance + $amount;

                $wallet->update([
                    'balance' => $walletBalanceAfter,
                    'available_balance' => $walletAvailableAfter,
                ]);

                WalletTransaction::withoutGlobalScopes()->create([
                    'group_id' => $group->id,
                    'wallet_id' => $wallet->id,
                    'member_id' => $member->id,
                    'type' => 'savings_withdrawal',
                    'direction' => 'credit',
                    'amount' => $amount,
                    'balance_after' => $walletBalanceAfter,
                    'status' => 'completed',
                    'reference' => $reference ?: 'WALLET-'.$transaction->id,
                    'idempotency_key' => $idempotencyKey ? $idempotencyKey.'-wallet' : null,
                    'description' => 'Savings withdrawal',
                    'journal_entry_id' => $journal->id,
                    'completed_at' => now(),
                ]);
            }

            return $transaction;
        });
    }

    public function pledge(SavingsAccount $account, int $amount): void
    {
        DB::transaction(function () use ($account, $amount) {
            $account = SavingsAccount::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            $newPledged = $account->pledged_amount + $amount;

            if ($newPledged > $account->balance) {
                throw new BusinessRuleException('Pledge exceeds the account balance.');
            }

            $account->update(['pledged_amount' => $newPledged]);
        });
    }

    public function releasePledge(SavingsAccount $account, int $amount): void
    {
        DB::transaction(function () use ($account, $amount) {
            $account = SavingsAccount::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            $account->update(['pledged_amount' => max(0, $account->pledged_amount - $amount)]);
        });
    }

    public function eligibleSavings(Member $member): int
    {
        return (int) SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->get()
            ->sum(fn (SavingsAccount $account) => $account->eligibleBalance());
    }

    public function totalSavings(Member $member): int
    {
        return (int) SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->sum('balance');
    }
}

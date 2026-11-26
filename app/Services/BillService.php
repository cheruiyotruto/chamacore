<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Group;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Support\PaymentChargeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillService
{
    public function __construct(
        private readonly PaymentChargeService $charges,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Amounts are already in minor units.
     */
    public function create(Group $group, array $data, User $actor): Bill
    {
        if ((int) $data['amount'] <= 0) {
            throw new BusinessRuleException('Bill amount must be greater than zero.');
        }

        $bill = Bill::withoutGlobalScopes()->create([
            ...$data,
            'group_id' => $group->id,
            'created_by' => $actor->id,
        ]);

        AuditService::log('bill.created', [
            'bill_id' => $bill->id,
            'bill_no' => $bill->bill_no,
            'amount' => $bill->amount,
            'payee' => $bill->payeeLabel(),
        ], $group);

        return $bill;
    }

    public function payWithWallet(Member $payer, Bill $bill, int $amount, ?int $manualCharge, User $actor): BillPayment
    {
        $this->ensurePayable($payer, $bill, $amount);

        $wallet = $this->wallets->ensureWallet($payer);

        if ($wallet->status !== 'active') {
            throw new BusinessRuleException('This wallet is not active.');
        }

        $reference = 'BILLP-'.strtoupper(Str::random(10));

        return DB::transaction(function () use ($payer, $bill, $amount, $manualCharge, $actor, $reference, $wallet) {
            $quote = $this->charges->quote(
                $bill->group()->firstOrFail(),
                $amount,
                $this->categories('wallet'),
                $manualCharge
            );

            if ($quote['payer_net_out'] > $wallet->available_balance) {
                throw new BusinessRuleException('Insufficient wallet balance to cover this payment and its charges.');
            }

            $payment = $this->record($bill, $payer, $amount, $quote, 'wallet', $reference, $actor, null);

            $this->wallets->recordJournalMovement(
                $payer,
                (int) $quote['payer_net_out'],
                'debit',
                'bill_payment',
                $reference,
                $payment->journalEntry()->firstOrFail(),
                'Bill payment · '.$bill->bill_no
            );

            // The bill owner is the payer/account holder, not a wallet payee:
            // the bill's value settles the BILL_PAYABLE liability to the biller.

            return $payment;
        });
    }

    public function payAtCounter(
        Group $group,
        Bill $bill,
        Member $payer,
        int $amount,
        string $method,
        ?int $manualCharge,
        User $actor,
        ?string $reference = null
    ): BillPayment {
        $this->ensurePayable($payer, $bill, $amount);

        if (! in_array($method, ['cash', 'bank'], true)) {
            throw new BusinessRuleException('Counter bill payments must be cash or bank.');
        }

        return DB::transaction(fn () => $this->record(
            $bill,
            $payer,
            $amount,
            $this->charges->quote($group, $amount, $this->categories($method), $manualCharge),
            $method,
            $reference ?: 'BILLC-'.strtoupper(Str::random(10)),
            $actor,
            null
        ));
    }

    public function applyMpesa(PaymentIntent $intent, Bill $bill, string $reference): BillPayment
    {
        $payer = Member::withoutGlobalScopes()->findOrFail($intent->payer_member_id ?: $intent->member_id);
        $base = (int) (data_get($intent->payload, 'base_amount') ?? $intent->amount);
        $quote = data_get($intent->payload, 'charge_quote');

        if (! is_array($quote)) {
            $quote = $this->charges->quote(
                $bill->group()->firstOrFail(),
                $base,
                $this->categories('mpesa'),
                data_get($intent->payload, 'manual_charge')
            );
        }

        return DB::transaction(fn () => $this->record(
            $bill,
            $payer,
            $base,
            $quote,
            'mpesa',
            $reference,
            null,
            $intent
        ));
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function record(
        Bill $bill,
        ?Member $payer,
        int $amount,
        array $quote,
        string $method,
        string $reference,
        ?User $actor,
        ?PaymentIntent $intent
    ): BillPayment {
        $group = Group::withoutGlobalScopes()->findOrFail($bill->group_id);

        $journal = $this->charges->postPaymentJournal($group, $quote, [
            'counter_code' => match ($method) {
                'wallet' => LedgerService::WALLET_LIABILITY,
                'cash' => LedgerService::CASH,
                default => LedgerService::BANK,
            },
            'payer' => $payer,
            'payee' => null,
            'payee_account_code' => LedgerService::BILL_PAYABLE,
            'reference' => $reference,
            'source' => null,
            'description' => 'Bill payment · '.$bill->bill_no,
        ]);

        $payment = BillPayment::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'bill_id' => $bill->id,
            'member_id' => $payer?->id,
            'payment_intent_id' => $intent?->id,
            'amount' => $amount,
            'settlement_amount' => (int) ($quote['net_to_payee'] ?? $amount),
            'charge_amount' => (int) ($quote['total_fee'] ?? 0),
            'charge_breakdown' => $quote['lines'] ?? [],
            'borne_by' => $this->borneBy($quote),
            'method' => $method,
            'reference' => $reference,
            'journal_entry_id' => $journal->id,
            'recorded_by' => $actor?->id,
            'paid_at' => now(),
        ]);

        $locked = Bill::withoutGlobalScopes()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
        $locked->amount_paid = (int) $locked->amount_paid + $amount;
        $locked->refreshStatus();
        $locked->save();

        AuditService::log('bill.payment.recorded', [
            'bill_id' => $bill->id,
            'bill_payment_id' => $payment->id,
            'amount' => $amount,
            'charge' => $payment->charge_amount,
            'method' => $method,
            'reference' => $reference,
        ], $group);

        return $payment;
    }

    private function ensurePayable(Member $payer, Bill $bill, int $amount): void
    {
        if ($bill->group_id !== $payer->group_id) {
            throw new BusinessRuleException('This bill does not belong to your group.');
        }

        if (! in_array($bill->status, [
            Bill::STATUS_PENDING,
            Bill::STATUS_PARTIALLY_PAID,
            Bill::STATUS_OVERDUE,
        ], true)) {
            throw new BusinessRuleException('This bill can no longer be paid.');
        }

        if ($amount <= 0) {
            throw new BusinessRuleException('Payment amount must be greater than zero.');
        }

        if ($amount > $bill->outstanding()) {
            throw new BusinessRuleException('Payment exceeds the outstanding bill balance.');
        }

        // Ownership is enforced by the portal controller. Staff may settle any
        // member bill from the counter or admin forms.
    }

    /**
     * @return array<int, string>
     */
    private function categories(string $method): array
    {
        return PaymentChargeSettings::categoriesFor(
            'bill_payment',
            $method === 'mpesa' ? 'mpesa' : $method,
            $method
        );
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function borneBy(array $quote): string
    {
        foreach ([
            PaymentChargeSettings::BORNE_BY_PAYER,
            PaymentChargeSettings::BORNE_BY_PAYEE,
            PaymentChargeSettings::BORNE_BY_GROUP,
        ] as $option) {
            if (($quote[$option.'_fee'] ?? 0) > 0) {
                return $option;
            }
        }

        return PaymentChargeSettings::BORNE_BY_PAYER;
    }
}

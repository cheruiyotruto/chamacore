<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Bill;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\OnBehalfPayment;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Services\Gateways\MpesaGateway;
use App\Support\Money;
use App\Support\PaymentChargeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    private const PURPOSES = [
        'wallet_topup', 'savings_deposit', 'loan_repayment', 'bill_payment', 'mgr_contribution',
    ];

    public function __construct(
        private readonly MpesaGateway $mpesa,
        private readonly WalletService $wallet,
        private readonly SavingsService $savings,
        private readonly LoanServicingService $loans,
        private readonly PaymentChargeService $charges,
        private readonly BillService $bills,
        private readonly MerryGoRoundService $mgr,
    ) {}

    /**
     * Start an M-Pesa payment. `$member` is the beneficiary whose savings,
     * loan, bill, contribution or wallet receives the payment; `$payer` is the
     * member funding it. When omitted the beneficiary is also the payer.
     *
     * @param  array{purpose:string, amount:int, phone:string, payload?:array}  $data
     */
    public function initiate(
        Member $member,
        array $data,
        ?string $idempotencyKey = null,
        ?Member $payer = null
    ): PaymentIntent {
        $payer = $payer ?? $member;

        if ($payer->group_id !== $member->group_id) {
            throw new BusinessRuleException('The payer and beneficiary must belong to the same group.');
        }

        $base = (int) $data['amount'];

        if ($base <= 0) {
            throw new BusinessRuleException('Payment amount must be greater than zero.');
        }

        if (! in_array($data['purpose'], self::PURPOSES, true)) {
            throw new BusinessRuleException('Unsupported payment purpose.');
        }

        if (! $this->mpesa->isConfigured()) {
            throw new BusinessRuleException(
                'Mobile money is not configured on this installation. Use cash, bank or wallet instead.'
            );
        }

        $group = Group::withoutGlobalScopes()->findOrFail($member->group_id);
        $payload = $data['payload'] ?? [];
        $bill = null;

        if ($data['purpose'] === 'bill_payment') {
            $bill = Bill::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereKey((int) data_get($payload, 'bill_id'))
                ->first();

            if (! $bill || $bill->outstanding() < $base) {
                throw new BusinessRuleException('The selected bill is not available for this amount.');
            }

            if ($bill->member_id && $bill->member_id !== $member->id) {
                throw new BusinessRuleException('You can only pay your own bills.');
            }

            $payload['bill_id'] = $bill->id;
        }

        if ($data['purpose'] === 'mgr_contribution') {
            $contribution = MerryGoRoundContribution::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereKey((int) data_get($payload, 'contribution_id'))
                ->first();

            if (! $contribution) {
                throw new BusinessRuleException('The selected merry-go-round contribution was not found.');
            }

            $this->mgr->assertPayableByMember($member, $contribution);
            $totals = $this->mgr->portalAmount($contribution);

            if ($base !== $totals['total']) {
                throw new BusinessRuleException(
                    'The amount must match the contribution and any late penalty ('.Money::format($totals['total']).').'
                );
            }

            $payload['contribution_id'] = $contribution->id;
            $payload['penalty_amount'] = $totals['penalty'];
        }

        $manualCharge = data_get($payload, 'manual_charge');
        $quote = $this->charges->quote(
            $group,
            $base,
            PaymentChargeSettings::categoriesFor($data['purpose'], 'mpesa'),
            $manualCharge === null ? null : (int) $manualCharge
        );

        $total = (int) $quote['payable_by_payer'];
        $key = $idempotencyKey ?: (string) Str::uuid();
        $storedPayload = array_merge($payload, [
            'base_amount' => $base,
            'charge_quote' => $quote,
        ]);

        return DB::transaction(function () use ($member, $payer, $group, $data, $total, $key, $storedPayload) {
            $intent = PaymentIntent::withoutGlobalScopes()
                ->where('group_id', $member->group_id)
                ->where('idempotency_key', $key)
                ->first();

            if ($intent) {
                return $intent;
            }

            $intent = PaymentIntent::withoutGlobalScopes()->create([
                'group_id' => $member->group_id,
                'member_id' => $member->id,
                'payer_member_id' => $payer->id !== $member->id ? $payer->id : null,
                'purpose' => $data['purpose'],
                'amount' => $total,
                'provider' => 'mpesa',
                'phone' => $data['phone'],
                'status' => 'pending',
                'idempotency_key' => $key,
                'payload' => $storedPayload,
            ]);

            if ($payer->id !== $member->id) {
                AuditService::log('payment.initiated_on_behalf_of', [
                    'payment_intent_id' => $intent->id,
                    'payer_member_id' => $payer->id,
                    'member_id' => $member->id,
                    'purpose' => $data['purpose'],
                    'amount' => $intent->amount,
                ], $group);
            }

            try {
                $result = $this->mpesa->initiate([
                    'amount' => $total,
                    'phone' => $data['phone'],
                    'reference' => 'CHAMA-'.$intent->id,
                    'description' => ucfirst(str_replace('_', ' ', $data['purpose'])),
                    'callback_url' => config('services.mpesa.callback_url'),
                ]);
            } catch (\Throwable $e) {
                $intent->update([
                    'status' => 'failed',
                    'failure_reason' => $e->getMessage(),
                    'completed_at' => now(),
                ]);

                throw $e;
            }

            $intent->update([
                'status' => 'processing',
                'provider_reference' => $result['reference'],
                'payload' => array_merge($storedPayload, ['initiation' => $result['response']]),
            ]);

            return $intent->refresh();
        });
    }

    /**
     * Handle a Daraja STK callback. Always idempotent: a callback is applied at
     * most once even if the provider retries or sends duplicates.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleMpesaCallback(array $payload): array
    {
        $callback = data_get($payload, 'Body.stkCallback');

        if (! is_array($callback)) {
            throw new BusinessRuleException('Malformed M-Pesa callback payload.');
        }

        $checkoutId = (string) ($callback['CheckoutRequestID'] ?? '');
        $resultCode = (int) ($callback['ResultCode'] ?? -1);

        if ($checkoutId === '') {
            throw new BusinessRuleException('Callback has no checkout request id.');
        }

        return DB::transaction(function () use ($checkoutId, $resultCode, $callback) {
            $intent = PaymentIntent::withoutGlobalScopes()
                ->where('provider', 'mpesa')
                ->where('provider_reference', $checkoutId)
                ->lockForUpdate()
                ->first();

            if (! $intent) {
                throw new BusinessRuleException('Unknown payment reference.');
            }

            if ($intent->status === 'succeeded') {
                return ['status' => 'duplicate', 'message' => 'Already processed.'];
            }

            $items = collect(data_get($callback, 'CallbackMetadata.Item', []))
                ->mapWithKeys(fn ($item) => [(string) ($item['Name'] ?? '') => $item['Value'] ?? null])
                ->all();

            $receipt = (string) ($items['MpesaReceiptNumber'] ?? '');
            // M-Pesa sends decimal strings in major units; keep supporting the
            // integer minor-unit values used by existing integrations/tests.
            $providerAmount = $items['Amount'] ?? 0;
            $paid = is_int($providerAmount)
                ? $providerAmount
                : Money::toMinor((string) $providerAmount);

            if ($resultCode !== 0) {
                $intent->update([
                    'status' => 'failed',
                    'failure_reason' => (string) ($callback['ResultDesc'] ?? 'Payment failed.'),
                    'completed_at' => now(),
                ]);
                $this->markOnBehalfStatus($intent, 'failed');

                return ['status' => 'failed', 'message' => 'Payment failed.'];
            }

            if ($paid !== $intent->amount) {
                $intent->update([
                    'status' => 'failed',
                    'failure_reason' => 'Amount mismatch: expected '.$intent->amount.', received '.$paid.'.',
                    'completed_at' => now(),
                ]);
                $this->markOnBehalfStatus($intent, 'failed');

                return [
                    'status' => 'ignored',
                    'message' => 'Payment amount does not match the request.',
                ];
            }

            // Keep provider_reference as the Daraja CheckoutRequestID so a
            // retried callback can still find this intent. The M-Pesa receipt
            // is recorded in the payload instead.
            $intent->update([
                'status' => 'processing',
                'payload' => array_merge($intent->payload ?? [], [
                    'callback' => $callback,
                    'receipt' => $receipt ?: data_get($intent->payload, 'receipt'),
                ]),
            ]);

            $member = Member::withoutGlobalScopes()->findOrFail($intent->member_id);
            $payer = $intent->payer_member_id
                ? Member::withoutGlobalScopes()->findOrFail($intent->payer_member_id)
                : $member;
            $group = Group::withoutGlobalScopes()->findOrFail($intent->group_id);
            $reference = $receipt ?: 'MPESA-'.$intent->id;
            $base = (int) (data_get($intent->payload, 'base_amount') ?? $intent->amount);
            $quote = data_get($intent->payload, 'charge_quote');

            if (! is_array($quote)) {
                $quote = $this->charges->quote(
                    $group,
                    $base,
                    PaymentChargeSettings::categoriesFor($intent->purpose, 'mpesa'),
                    data_get($intent->payload, 'manual_charge')
                );
            }

            if ($intent->purpose === 'bill_payment') {
                $bill = Bill::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->whereKey((int) data_get($intent->payload, 'bill_id'))
                    ->firstOrFail();

                $this->bills->applyMpesa($intent, $bill, $reference);
            } elseif ($intent->purpose === 'mgr_contribution') {
                $contribution = MerryGoRoundContribution::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->whereKey((int) data_get($intent->payload, 'contribution_id'))
                    ->firstOrFail();

                $this->mgr->applyMpesaContribution($intent, $contribution, $reference);

                $this->charges->postFees(
                    $group,
                    $quote,
                    LedgerService::BANK,
                    $payer,
                    $reference,
                    $intent
                );
            } else {
                $applyAmount = max(0, $base - (int) ($quote['payee_fee'] ?? 0));

                if ($applyAmount > 0) {
                    match ($intent->purpose) {
                        'wallet_topup' => $this->wallet->credit(
                            $member,
                            $applyAmount,
                            'mpesa_topup',
                            LedgerService::BANK,
                            $reference,
                            'mpesa-'.$intent->id,
                            'M-Pesa wallet top-up',
                            $intent
                        ),
                        'savings_deposit' => $this->applySavings($intent, $member, $payer, $reference, $applyAmount),
                        'loan_repayment' => $this->applyLoan($intent, $member, $payer, $reference, $applyAmount),
                        default => throw new BusinessRuleException('Unsupported payment purpose.'),
                    };
                }

                $this->charges->postFees(
                    $group,
                    $quote,
                    LedgerService::BANK,
                    $payer,
                    $reference,
                    $intent
                );
            }

            $intent->update([
                'status' => 'succeeded',
                'completed_at' => now(),
                'failure_reason' => null,
            ]);
            $this->markOnBehalfStatus($intent, 'succeeded');

            AuditService::log('payment.succeeded', [
                'payment_intent_id' => $intent->id,
                'purpose' => $intent->purpose,
                'base_amount' => $base,
                'amount' => $intent->amount,
                'charge' => $quote['total_fee'] ?? 0,
                'receipt' => $reference,
                'payer_member_id' => $payer->id,
                'beneficiary_member_id' => $member->id,
            ], $group);

            return ['status' => 'succeeded', 'message' => 'Payment applied.'];
        });
    }

    private function applySavings(
        PaymentIntent $intent,
        Member $member,
        Member $payer,
        string $reference,
        int $base
    ): void {
        $accountId = (int) data_get($intent->payload, 'savings_account_id');

        $account = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->when($accountId, fn ($q) => $q->where('id', $accountId))
            ->first();

        if (! $account) {
            throw new BusinessRuleException('No savings account was found for this payment.');
        }

        $this->savings->deposit(
            member: $member,
            account: $account,
            amount: $base,
            type: 'deposit',
            counterAccountCode: LedgerService::BANK,
            reference: $reference,
            idempotencyKey: 'mpesa-'.$intent->id,
            description: 'M-Pesa savings deposit',
            source: $intent,
            payer: $payer
        );
    }

    private function applyLoan(
        PaymentIntent $intent,
        Member $member,
        Member $payer,
        string $reference,
        int $base
    ): void {
        $loanId = (int) data_get($intent->payload, 'loan_id');

        $loan = Loan::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->when($loanId, fn ($q) => $q->where('id', $loanId))
            ->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_DISBURSED, Loan::STATUS_RESTRUCTURED])
            ->first();

        if (! $loan) {
            throw new BusinessRuleException('No active loan was found for this payment.');
        }

        $this->loans->recordPayment(
            loan: $loan,
            amount: $base,
            method: 'mpesa',
            reference: $reference,
            idempotencyKey: 'mpesa-'.$intent->id,
            notes: 'M-Pesa repayment',
            payer: $payer
        );
    }

    private function markOnBehalfStatus(PaymentIntent $intent, string $status): void
    {
        OnBehalfPayment::withoutGlobalScopes()
            ->where('payment_intent_id', $intent->id)
            ->update(['status' => $status]);
    }
}

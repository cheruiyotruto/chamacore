<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Bill;
use App\Models\Group;
use App\Models\Member;
use App\Models\Payout;
use App\Models\PaymentDestination;
use App\Models\User;
use App\Services\Gateways\PayoutGatewayResolver;
use App\Support\GroupContext;
use App\Support\Money;
use App\Support\PaymentChargeSettings;
use App\Support\PayoutAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bank transfers and M-Pesa payouts.
 *
 * Funds are reserved from the bill collections liability (or a member wallet)
 * into a clearing account when the payout is created. A successful provider
 * callback moves the reservation to the bank account and charge income; a
 * failed callback releases it. Journals are therefore always balanced and a
 * duplicate callback can never move money twice.
 */
class PayoutService
{
    public function __construct(
        private readonly PaymentChargeService $charges,
        private readonly PayoutGatewayResolver $gateways,
        private readonly LedgerService $ledger,
        private readonly WalletService $wallets,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function quote(Group $group, int $amount, string $provider, ?int $manualCharge = null): array
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('The transfer amount must be greater than zero.');
        }

        $quote = $this->charges->quote(
            $group,
            $amount,
            PaymentChargeSettings::categoriesFor('payout', $provider),
            $manualCharge
        );

        return array_merge($quote, [
            'reserved_from_source' => $amount + (int) $quote['payer_fee'],
            'net_to_recipient' => max(0, $amount - (int) $quote['payee_fee']),
        ]);
    }

    public static function memberAccessEnabled(Group $group, Member $member): bool
    {
        return $member->can_bank_transfer
            && $member->status === 'active'
            && PayoutAccess::groupEnabled($group);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Group $group, array $data, User $actor): Payout
    {
        $destination = PaymentDestination::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->whereKey((int) $data['destination_id'])
            ->first();

        if (! $destination || ! $destination->is_active) {
            throw new BusinessRuleException('Select an active payment destination in this group.');
        }

        $amount = (int) $data['amount'];
        $canManage = app(GroupContext::class)->hasPermission('payouts.manage');
        $bill = null;
        $member = null;

        // Members may only send money from their own wallet, to destinations
        // configured by the group, using the group's standard charges. Funding
        // from collected bill funds and manual fee overrides are staff-only.
        if (! $canManage) {
            $source = Payout::SOURCE_WALLET;
            $data['bill_id'] = null;
            $data['manual_charge'] = null;
            $data['member_id'] = app(GroupContext::class)->memberId();

            $member = Member::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereKey((int) ($data['member_id'] ?? 0))
                ->first();

            if (! $member || $member->user_id !== $actor->id) {
                throw new BusinessRuleException('You can only send money from your own wallet.');
            }

            if (! self::memberAccessEnabled($group, $member)) {
                throw new BusinessRuleException(
                    'Bank transfers are not enabled for your account. Ask an administrator to enable them.'
                );
            }
        } else {
            $requestedSource = $data['source'] ?? Payout::SOURCE_BILLS;
            $source = in_array($requestedSource, [
                Payout::SOURCE_BILLS, Payout::SOURCE_WALLET,
            ], true) ? $requestedSource : Payout::SOURCE_BILLS;
        }

        $quote = $this->quote($group, $amount, $destination->provider(), $data['manual_charge'] ?? null);

        if (! empty($data['bill_id'])) {
            $bill = Bill::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereKey((int) $data['bill_id'])
                ->first();

            if (! $bill || $bill->settlementBalance() < $amount) {
                throw new BusinessRuleException('The selected bill does not have enough collected funds to transfer.');
            }
        }

        if ($source === Payout::SOURCE_WALLET) {
            $member ??= Member::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereKey((int) ($data['member_id'] ?? 0))
                ->first();

            if (! $member) {
                throw new BusinessRuleException('Select the member whose wallet will fund this transfer.');
            }

            if (! $canManage && $member->user_id !== $actor->id) {
                throw new BusinessRuleException('You can only send money from your own wallet.');
            }

            $wallet = $this->wallets->ensureWallet($member);

            if ($wallet->status !== 'active' || $wallet->available_balance < $quote['reserved_from_source']) {
                throw new BusinessRuleException('The wallet cannot cover this transfer and its charges.');
            }
        }

        $key = (string) ($data['idempotency_key'] ?? (string) Str::uuid());
        $reference = 'PAYOUT-'.strtoupper(Str::random(10));

        return DB::transaction(function () use (
            $group, $destination, $amount, $quote, $source, $bill, $member, $actor, $key, $reference
        ) {
            $existing = Payout::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                return $existing;
            }

            $payout = Payout::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'destination_id' => $destination->id,
                'source' => $source,
                'bill_id' => $bill?->id,
                'member_id' => $member?->id,
                'amount' => $amount,
                'net_amount' => (int) $quote['net_to_recipient'],
                'charge_amount' => (int) $quote['total_fee'],
                'total_amount' => (int) $quote['reserved_from_source'],
                'charge_breakdown' => $quote['lines'],
                'borne_by' => $this->borneBy($quote),
                'currency' => $group->currency ?: 'KES',
                'provider' => $destination->provider(),
                'status' => Payout::STATUS_PENDING,
                'idempotency_key' => $key,
                'requested_by' => $actor->id,
                'approved_by' => null,
                'payload' => [
                    'charge_quote' => $quote,
                    'destination' => [
                        'name' => $destination->name,
                        'type' => $destination->type,
                        'label' => $destination->label(),
                    ],
                    'reference' => $reference,
                    'source' => $source,
                ],
            ]);

            $this->reserve($payout, $quote, $member);

            AuditService::log('payout.created', [
                'payout_id' => $payout->id,
                'destination_id' => $destination->id,
                'amount' => $amount,
                'charge' => $payout->charge_amount,
                'source' => $source,
                'reference' => $reference,
            ], $group);

            return $payout->refresh();
        });
    }

    public function initiate(Payout $payout, ?User $actor = null): Payout
    {
        if ($actor && ! app(GroupContext::class)->hasPermission('payouts.manage')
            && ($payout->source !== Payout::SOURCE_WALLET || $payout->requested_by !== $actor->id)) {
            throw new BusinessRuleException('You can only submit your own wallet transfers.');
        }

        if ($payout->status !== Payout::STATUS_PENDING) {
            throw new BusinessRuleException('Only a pending transfer can be sent.');
        }

        $gateway = $this->gateways->for($payout->provider);
        $destination = $payout->destination()->firstOrFail();
        $command = match ($destination->type) {
            PaymentDestination::TYPE_PAYBILL => 'BusinessPayBill',
            PaymentDestination::TYPE_TILL => 'BusinessBuyGoods',
            PaymentDestination::TYPE_PHONE => 'BusinessPayment',
            default => null,
        };
        $recipientType = $destination->type === PaymentDestination::TYPE_PHONE ? '1' : '4';
        $reference = (string) data_get($payout->payload, 'reference', 'PAYOUT-'.$payout->id);

        $request = [
            'amount' => $payout->net_amount,
            'currency' => $payout->currency,
            'reference' => $reference,
            'description' => 'Bank transfer / payout '.$reference,
            'idempotency_key' => $payout->idempotency_key ?: (string) $payout->uuid,
            'bank_name' => $destination->bank_name,
            'account_name' => $destination->account_name,
            'account_number' => $destination->account_number,
            'paybill_number' => $destination->paybill_number,
            'till_number' => $destination->till_number,
            'shortcode' => $destination->shortcode,
            'phone' => $destination->phone,
            'command_id' => $command,
            'recipient_type' => $recipientType,
            'callback_url' => $payout->provider === 'bank'
                ? config('services.bank_payouts.callback_url')
                : config('services.mpesa.b2b_result_url'),
            'queue_url' => config('services.mpesa.b2b_queue_url'),
            'result_url' => config('services.mpesa.b2b_result_url'),
        ];

        try {
            $result = $gateway->initiate($request);
        } catch (Throwable $e) {
            $this->fail($payout, $e->getMessage());

            throw $e;
        }

        $payout->update([
            'status' => Payout::STATUS_PROCESSING,
            'provider_reference' => $result['reference'] ?: $payout->idempotency_key,
            'approved_by' => $actor?->id,
            'payload' => array_merge($payout->payload ?? [], ['initiation' => $result['response']]),
        ]);

        AuditService::log('payout.initiated', [
            'payout_id' => $payout->id,
            'provider' => $payout->provider,
            'provider_reference' => $payout->provider_reference,
        ], $payout->group()->firstOrFail());

        return $payout->refresh();
    }

    /**
     * Apply an idempotent provider callback. Both Daraja B2B results and the
     * generic bank webhook are normalised to succeeded/failed/processing.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function handleCallback(string $provider, array $payload): array
    {
        if (! in_array($provider, ['bank', 'mpesa'], true)) {
            throw new BusinessRuleException('Unknown payout provider.');
        }

        if ($provider === 'mpesa') {
            $result = is_array(data_get($payload, 'Result')) ? data_get($payload, 'Result') : $payload;
            $reference = (string) (data_get($result, 'OriginatorConversationID')
                ?: data_get($result, 'ConversationID')
                ?: data_get($payload, 'OriginatorConversationID')
                ?: '');
            $code = (int) (data_get($result, 'ResultCode', -1));
            $status = $code === 0 ? 'succeeded' : 'failed';
            $reason = (string) (data_get($result, 'ResultDesc') ?: 'M-Pesa payout failed.');
            $transaction = (string) (data_get($result, 'TransactionID') ?: '');
        } else {
            $reference = (string) (data_get($payload, 'reference')
                ?: data_get($payload, 'id')
                ?: data_get($payload, 'transaction_id')
                ?: data_get($payload, 'TransactionID')
                ?: '');
            $rawStatus = strtolower((string) (data_get($payload, 'status')
                ?: data_get($payload, 'ResultCode', '')));
            $status = in_array($rawStatus, ['completed', 'success', 'succeeded', '0'], true)
                ? 'succeeded'
                : (in_array($rawStatus, ['failed', 'rejected', 'declined', 'cancelled'], true)
                    ? 'failed'
                    : 'processing');
            $reason = (string) (data_get($payload, 'reason') ?: 'Bank transfer failed.');
            $transaction = (string) (data_get($payload, 'TransactionID') ?: data_get($payload, 'transaction_id') ?: '');
        }

        if ($reference === '') {
            throw new BusinessRuleException('Callback has no payout reference.');
        }

        return DB::transaction(function () use ($provider, $reference, $status, $reason, $transaction, $payload) {
            /** @var Payout|null $payout */
            $payout = Payout::withoutGlobalScopes()
                ->where('provider', $provider)
                ->where(function ($query) use ($reference) {
                    $query->where('provider_reference', $reference)
                        ->orWhere('idempotency_key', $reference);
                })
                ->lockForUpdate()
                ->first();

            if (! $payout) {
                throw new BusinessRuleException('Unknown payout reference.');
            }

            // Providers that report an amount must agree with the stored payout.
            $callbackAmount = data_get($payload, 'Amount')
                ?? data_get($payload, 'amount')
                ?? data_get($payload, 'TransAmount')
                ?? data_get($payload, 'Result.Amount');

            if ($callbackAmount !== null && $callbackAmount !== '') {
                try {
                    $paid = Money::toMinor($callbackAmount);
                } catch (Throwable) {
                    throw new BusinessRuleException('Callback amount is invalid.');
                }

                if (! in_array($paid, [$payout->net_amount, $payout->amount, $payout->total_amount], true)) {
                    throw new BusinessRuleException('Callback amount does not match the payout.');
                }
            }

            if ($payout->isFinal()) {
                return ['status' => 'duplicate', 'message' => 'Already processed.'];
            }

            if ($status === 'processing') {
                return ['status' => 'processing', 'message' => 'Awaiting final status.'];
            }

            if ($status === 'succeeded') {
                $this->settle($payout, $transaction ?: null, $payload);

                return ['status' => 'succeeded', 'message' => 'Payout completed.'];
            }

            $this->fail($payout, $reason, $payload);

            return ['status' => 'failed', 'message' => $reason];
        });
    }

    public function cancel(Payout $payout, string $reason = 'Cancelled by an administrator'): Payout
    {
        if ($payout->isFinal()) {
            throw new BusinessRuleException('This transfer has already been completed.');
        }

        return DB::transaction(function () use ($payout, $reason) {
            $this->release($payout, $reason);
            $payout->update([
                'status' => Payout::STATUS_CANCELLED,
                'failure_reason' => $reason,
            ]);

            return $payout->refresh();
        });
    }

    /**
     * Reserve the source funds in the clearing account.
     *
     * @param  array<string, mixed>  $quote
     */
    private function reserve(Payout $payout, array $quote, ?Member $member): void
    {
        $source = (int) $quote['reserved_from_source'];
        $groupFee = (int) $quote['group_fee'];

        if ($source <= 0) {
            throw new BusinessRuleException('The transfer amount must be greater than zero.');
        }

        $lines = [
            [
                'code' => $this->sourceAccount($payout),
                'debit' => $source,
                'member_id' => $member?->id,
                'description' => 'Funds reserved for payout '.$payout->uuid,
            ],
        ];

        if ($groupFee > 0) {
            $lines[] = [
                'code' => LedgerService::OPERATING_EXPENSE,
                'debit' => $groupFee,
                'description' => 'Group-borne payout charge',
            ];
        }

        $lines[] = [
            'code' => LedgerService::PAYOUT_CLEARING,
            'credit' => $source + $groupFee,
            'description' => 'Payout reservation',
        ];

        $journal = $this->ledger->post(
            $payout->group()->firstOrFail(),
            'Payout reservation · '.$payout->uuid,
            $lines,
            null,
            $payout,
            (string) data_get($payout->payload, 'reference')
        );

        $payload = $payout->payload ?? [];
        $payload['reservation_journal_id'] = $journal->id;
        $payout->update(['journal_entry_id' => $journal->id, 'payload' => $payload]);

        if ($member) {
            $this->wallets->recordJournalMovement(
                $member,
                $source,
                'debit',
                'bank_transfer',
                (string) data_get($payout->payload, 'reference'),
                $journal,
                'Bank transfer / payout reservation'
            );
        }
    }

    /**
     * Move the reservation to the bank and charge income once the provider
     * confirms the money left the group's account.
     *
     * @param  array<string, mixed>  $payload
     */
    private function settle(Payout $payout, ?string $transaction, array $payload): void
    {
        $group = $payout->group()->firstOrFail();
        $quote = data_get($payout->payload, 'charge_quote', []);
        $payerFee = (int) ($quote['payer_fee'] ?? 0);
        $payeeFee = (int) ($quote['payee_fee'] ?? 0);
        $groupFee = (int) ($quote['group_fee'] ?? 0);
        $reserved = $payout->amount + $payerFee + $groupFee;

        $lines = [[
            'code' => LedgerService::PAYOUT_CLEARING,
            'debit' => $reserved,
            'description' => 'Payout settled',
        ], [
            'code' => LedgerService::BANK,
            'credit' => max(0, $payout->net_amount + $groupFee),
            'description' => 'Cash sent to payment destination',
        ]];

        foreach ($this->charges->incomeLines($quote, $group) as $code => $amount) {
            $lines[] = [
                'code' => $code,
                'credit' => $amount,
                'description' => 'Payment charge income',
            ];
        }

        // A zero-fee payout still needs a bank credit equal to the reservation.
        $journal = $this->ledger->post(
            $group,
            'Payout settled · '.$payout->uuid,
            $lines,
            null,
            $payout,
            (string) data_get($payout->payload, 'reference')
        );

        $stored = $payout->payload ?? [];
        $stored['settlement_journal_id'] = $journal->id;
        $stored['callback'] = $payload;

        $payout->update([
            'status' => Payout::STATUS_SUCCEEDED,
            'provider_reference' => $transaction ?: $payout->provider_reference,
            'paid_at' => now(),
            'failure_reason' => null,
            'payload' => $stored,
        ]);

        AuditService::log('payout.succeeded', [
            'payout_id' => $payout->id,
            'amount' => $payout->amount,
            'provider_reference' => $payout->provider_reference,
        ], $group);
    }

    /**
     * Reverse a failed or cancelled transfer and release the source funds.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function fail(Payout $payout, string $reason, ?array $payload = null): void
    {
        DB::transaction(function () use ($payout, $reason, $payload) {
            $this->release($payout, $reason);
            $payout->update([
                'status' => Payout::STATUS_FAILED,
                'failure_reason' => $reason,
                'payload' => array_merge($payout->payload ?? [], $payload ? ['callback' => $payload] : []),
            ]);
        });
    }

    private function release(Payout $payout, string $reason): void
    {
        if (data_get($payout->payload, 'released_journal_id')) {
            return;
        }

        $quote = data_get($payout->payload, 'charge_quote', []);
        $payerFee = (int) ($quote['payer_fee'] ?? 0);
        $groupFee = (int) ($quote['group_fee'] ?? 0);
        $source = $payout->amount + $payerFee;
        $member = $payout->member_id ? Member::withoutGlobalScopes()->find($payout->member_id) : null;

        $lines = [[
            'code' => LedgerService::PAYOUT_CLEARING,
            'debit' => $source + $groupFee,
            'description' => 'Payout reservation released',
        ], [
            'code' => $this->sourceAccount($payout),
            'credit' => $source,
            'member_id' => $member?->id,
            'description' => 'Payout funds returned to source',
        ]];

        if ($groupFee > 0) {
            $lines[] = [
                'code' => LedgerService::OPERATING_EXPENSE,
                'credit' => $groupFee,
                'description' => 'Group-borne payout charge reversed',
            ];
        }

        $journal = $this->ledger->post(
            $payout->group()->firstOrFail(),
            'Payout failed · '.$payout->uuid.': '.$reason,
            $lines,
            null,
            $payout,
            (string) data_get($payout->payload, 'reference')
        );

        $stored = $payout->payload ?? [];
        $stored['released_journal_id'] = $journal->id;
        $payout->update(['payload' => $stored]);

        if ($member) {
            $this->wallets->recordJournalMovement(
                $member,
                $source,
                'credit',
                'bank_transfer_refund',
                (string) data_get($payout->payload, 'reference'),
                $journal,
                'Bank transfer / payout refund'
            );
        }

        AuditService::log('payout.released', [
            'payout_id' => $payout->id,
            'reason' => $reason,
        ], $payout->group()->firstOrFail());
    }

    private function sourceAccount(Payout $payout): string
    {
        return $payout->source === Payout::SOURCE_WALLET
            ? LedgerService::WALLET_LIABILITY
            : LedgerService::BILL_PAYABLE;
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

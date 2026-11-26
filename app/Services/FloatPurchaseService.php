<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\FloatPurchase;
use App\Models\Group;
use App\Models\Member;
use App\Models\MpesaAgent;
use App\Models\User;
use App\Services\Gateways\FloatGatewayResolver;
use App\Support\FloatAccess;
use App\Support\KenyanPhone;
use App\Support\Money;
use App\Support\PaymentChargeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wallet-funded M-Pesa float purchases.
 *
 * The destination is an M-Pesa agent store, identified by agent number and
 * store number (the phone is the agent's registered line). The member's wallet
 * is debited into the payout clearing account when the purchase is created.
 * A configured provider is called immediately; with the default manual driver
 * the purchase stays pending for a treasurer.
 * Completing the purchase settles clearing to bank plus charge income; failing
 * it reverses the reservation and credits the wallet back in full.
 */
class FloatPurchaseService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PaymentChargeService $charges,
        private readonly WalletService $wallets,
        private readonly FloatGatewayResolver $gateways,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @return array<int, array{major:int, minor:int, label:string}>
     */
    public function packages(): array
    {
        $currency = (string) config('chama.currency', 'KES');

        return collect(config('chama.float.packages', []))
            ->map(fn ($major) => [
                'major' => (int) $major,
                'minor' => (int) $major * 100,
                'label' => Money::format((int) $major * 100, $currency),
            ])
            ->all();
    }

    /**
     * @return array<string, int|array>
     */
    public function quote(Group $group, int $amount): array
    {
        $quote = $this->charges->quote($group, $amount, [
            PaymentChargeSettings::ALL_PAYMENTS,
            PaymentChargeSettings::FLOAT_PURCHASES,
        ]);

        // There is no recipient wallet to deduct from, so every customer charge
        // is paid by the member buying the float.
        $customerFee = (int) $quote['payer_fee'] + (int) $quote['payee_fee'];
        $groupFee = (int) $quote['group_fee'];

        return [
            'lines' => $quote['lines'],
            'payer_fee' => (int) $quote['payer_fee'],
            'payee_fee' => (int) $quote['payee_fee'],
            'group_fee' => $groupFee,
            'customer_fee' => $customerFee,
            'total_fee' => $customerFee + $groupFee,
            'base_amount' => $amount,
            'total' => $amount + $customerFee,
            'cleared' => $amount + $customerFee + $groupFee,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function purchase(Group $group, Member $member, User $actor, array $data): FloatPurchase
    {
        if (! FloatAccess::memberAccessEnabled($group, $member)) {
            throw new BusinessRuleException('M-Pesa float purchases are not enabled for your account.');
        }

        $amount = (int) ($data['amount'] ?? 0);
        $min = Money::toMinor(config('chama.float.min_amount', 10));
        $max = Money::toMinor(config('chama.float.max_amount', 10000));

        if ($amount < $min || $amount > $max) {
            throw new BusinessRuleException(
                'Float amount must be between '.Money::format($min).' and '.Money::format($max).'.'
            );
        }

        $network = strtolower((string) ($data['network'] ?? 'mpesa'));

        if ($network !== 'mpesa') {
            throw new BusinessRuleException('Only M-Pesa float is supported at the moment.');
        }

        $agent = $this->resolveAgent($group, $data);
        $phone = $agent['phone'];

        $key = (string) ($data['idempotency_key'] ?? (string) Str::uuid());
        $existing = FloatPurchase::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing) {
            return $existing;
        }

        $quote = $this->quote($group, $amount);
        $wallet = $this->wallets->ensureWallet($member);

        if ($wallet->status !== 'active' || $wallet->available_balance < $quote['total']) {
            throw new BusinessRuleException('Your wallet cannot cover this purchase and its charges.');
        }

        $reference = 'FLT-'.strtoupper(Str::random(10));
        $provider = (string) config('services.float.driver', 'manual');

        $purchase = DB::transaction(function () use (
            $group, $member, $actor, $amount, $phone, $network, $agent, $key, $quote, $reference, $provider
        ) {
            $duplicate = FloatPurchase::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->where('idempotency_key', $key)
                ->first();

            if ($duplicate) {
                return $duplicate;
            }

            $purchase = FloatPurchase::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'member_id' => $member->id,
                'reference' => $reference,
                'network' => $network,
                'phone' => $phone,
                'agent_id' => $agent['agent_id'],
                'agent_number' => $agent['agent_number'],
                'store_number' => $agent['store_number'],
                'agent_name' => $agent['agent_name'],
                'amount' => $amount,
                'charge_amount' => (int) $quote['customer_fee'],
                'total_amount' => (int) $quote['total'],
                'group_fee_amount' => (int) $quote['group_fee'],
                'currency' => $group->currency ?: 'KES',
                'status' => FloatPurchase::STATUS_PENDING,
                'provider' => $provider,
                'idempotency_key' => $key,
                'requested_by' => $actor->id,
                'payload' => [
                    'charge_quote' => $quote,
                    'reference' => $reference,
                    'agent' => $agent,
                ],
            ]);

            $debit = $this->wallets->debit(
                $member,
                (int) $quote['total'],
                'float_purchase',
                LedgerService::PAYOUT_CLEARING,
                $reference,
                'float-purchase-'.$key,
                'M-Pesa float · Agent '.$agent['agent_number'].' · Store '.$agent['store_number'],
                $purchase
            );

            $groupFeeJournal = null;

            if ($quote['group_fee'] > 0) {
                $groupFeeJournal = $this->ledger->post($group, 'Group-borne float charge · '.$reference, [
                    ['code' => LedgerService::OPERATING_EXPENSE, 'debit' => (int) $quote['group_fee'], 'description' => 'Float charge borne by group'],
                    ['code' => LedgerService::PAYOUT_CLEARING, 'credit' => (int) $quote['group_fee'], 'description' => 'Float charge funded by group'],
                ], now(), $purchase, $reference);
            }

            $payload = $purchase->payload ?? [];
            $payload['reservation_journal_id'] = $debit->journal_entry_id;
            $payload['group_fee_journal_id'] = $groupFeeJournal?->id;
            $purchase->update([
                'journal_entry_id' => $debit->journal_entry_id,
                'payload' => $payload,
            ]);

            AuditService::log('float_purchase.created', [
                'float_purchase_id' => $purchase->id,
                'member_id' => $member->id,
                'amount' => $amount,
                'charge' => $quote['customer_fee'],
                'phone' => $phone,
                'agent_number' => $agent['agent_number'],
                'store_number' => $agent['store_number'],
                'reference' => $reference,
            ], $group);

            return $purchase->refresh();
        });

        $gateway = $this->gateways->configured();

        if (! $gateway->isConfigured()) {
            return $purchase;
        }

        try {
            $result = $gateway->initiate([
                'uuid' => $purchase->uuid,
                'reference' => $purchase->reference,
                'group_id' => $group->id,
                'phone' => $purchase->phone,
                'agent_number' => $purchase->agent_number,
                'store_number' => $purchase->store_number,
                'agent_name' => $purchase->agent_name,
                'network' => $purchase->network,
                'amount' => $purchase->amount,
                'currency' => $purchase->currency,
                'callback_url' => config('services.float.callback_url'),
            ]);

            return $this->applyProviderResult($purchase, $result, $actor);
        } catch (BusinessRuleException $e) {
            return $this->fail($purchase, $actor, $e->getMessage());
        } catch (Throwable $e) {
            $this->fail($purchase, $actor, 'The airtime provider request failed.');
            throw $e;
        }
    }

    /**
     * @param  array{status:string, reference:?string, response:array}  $result
     */
    public function applyProviderResult(FloatPurchase $purchase, array $result, ?User $actor = null): FloatPurchase
    {
        if ($purchase->isFinal()) {
            return $purchase;
        }

        $status = (string) ($result['status'] ?? 'processing');
        $providerReference = $result['reference'] ?? null;

        if ($status === 'completed') {
            return $this->complete($purchase, $actor, $providerReference, $result['response'] ?? []);
        }

        if ($status === 'failed') {
            $reason = (string) ($result['response']['message'] ?? $result['response']['error'] ?? 'The airtime provider declined the purchase.');

            return $this->fail($purchase, $actor, $reason, $result['response'] ?? []);
        }

        $payload = $purchase->payload ?? [];
        $payload['provider_response'] = $result['response'] ?? [];
        $purchase->update([
            'status' => FloatPurchase::STATUS_PROCESSING,
            'provider_reference' => $providerReference ?: $purchase->provider_reference,
            'payload' => $payload,
        ]);

        AuditService::log('float_purchase.processing', [
            'float_purchase_id' => $purchase->id,
            'provider_reference' => $providerReference,
        ], $purchase->group()->firstOrFail());

        return $purchase->refresh();
    }

    public function complete(
        FloatPurchase $purchase,
        ?User $actor = null,
        ?string $providerReference = null,
        array $providerResponse = []
    ): FloatPurchase {
        if ($purchase->status === FloatPurchase::STATUS_COMPLETED) {
            return $purchase;
        }

        if (in_array($purchase->status, [
            FloatPurchase::STATUS_FAILED, FloatPurchase::STATUS_REFUNDED,
        ], true)) {
            throw new BusinessRuleException('A failed float purchase cannot be completed.');
        }

        $group = $purchase->group()->firstOrFail();

        DB::transaction(function () use ($purchase, $group, $actor, $providerReference, $providerResponse) {
            /** @var FloatPurchase $locked */
            $locked = FloatPurchase::withoutGlobalScopes()->lockForUpdate()->findOrFail($purchase->id);

            if ($locked->status === FloatPurchase::STATUS_COMPLETED) {
                return;
            }

            $quote = data_get($locked->payload, 'charge_quote', []);
            $lines = [[
                'code' => LedgerService::PAYOUT_CLEARING,
                'debit' => $locked->total_amount + $locked->group_fee_amount,
                'description' => 'Float purchase settled',
            ], [
                'code' => LedgerService::BANK,
                'credit' => $locked->amount + $locked->group_fee_amount,
                'description' => 'Float delivered to recipient',
            ]];

            $incomeLines = $this->charges->incomeLines($quote, $group);

            foreach ($incomeLines as $code => $amount) {
                $lines[] = [
                    'code' => $code,
                    'credit' => $amount,
                    'description' => 'Float purchase charge income',
                ];
            }

            $journal = $this->ledger->post(
                $group,
                'Float purchase settled · '.$locked->reference,
                $lines,
                now(),
                $locked,
                $locked->reference
            );

            $payload = $locked->payload ?? [];
            $payload['settlement_journal_id'] = $journal->id;

            if ($providerResponse !== []) {
                $payload['provider_response'] = $providerResponse;
            }

            $locked->update([
                'status' => FloatPurchase::STATUS_COMPLETED,
                'provider_reference' => $providerReference ?: $locked->provider_reference,
                'fulfilled_by' => $actor?->id,
                'completed_at' => now(),
                'failure_reason' => null,
                'payload' => $payload,
            ]);
        });

        $purchase->refresh();

        AuditService::log('float_purchase.completed', [
            'float_purchase_id' => $purchase->id,
            'amount' => $purchase->amount,
            'provider_reference' => $purchase->provider_reference,
        ], $group);

        $this->notifications->send($purchase->member()->firstOrFail(), 'float_purchase_completed', [
            'amount' => Money::format($purchase->amount, $purchase->currency),
            'agent_number' => $purchase->agent_number,
            'store_number' => $purchase->store_number,
            'phone' => $purchase->phone,
            'reference' => $purchase->reference,
        ]);

        return $purchase;
    }

    /**
     * Fail a pending/processing purchase and refund the member's wallet.
     */
    public function fail(
        FloatPurchase $purchase,
        ?User $actor = null,
        string $reason = 'The float purchase could not be completed.',
        array $providerResponse = []
    ): FloatPurchase {
        if (in_array($purchase->status, [
            FloatPurchase::STATUS_FAILED, FloatPurchase::STATUS_REFUNDED,
        ], true)) {
            return $purchase;
        }

        if ($purchase->status === FloatPurchase::STATUS_COMPLETED) {
            throw new BusinessRuleException('A completed float purchase cannot be failed. Reverse it from accounting instead.');
        }

        $group = $purchase->group()->firstOrFail();
        $member = $purchase->member()->firstOrFail();

        DB::transaction(function () use ($purchase, $group, $member, $actor, $reason, $providerResponse) {
            /** @var FloatPurchase $locked */
            $locked = FloatPurchase::withoutGlobalScopes()->lockForUpdate()->findOrFail($purchase->id);

            if (in_array($locked->status, [
                FloatPurchase::STATUS_FAILED, FloatPurchase::STATUS_REFUNDED, FloatPurchase::STATUS_COMPLETED,
            ], true)) {
                return;
            }

            $lines = [[
                'code' => LedgerService::PAYOUT_CLEARING,
                'debit' => $locked->total_amount + $locked->group_fee_amount,
                'description' => 'Float purchase reservation released',
            ], [
                'code' => LedgerService::WALLET_LIABILITY,
                'credit' => $locked->total_amount,
                'member_id' => $member->id,
                'description' => 'Float purchase refunded to wallet',
            ]];

            if ($locked->group_fee_amount > 0) {
                $lines[] = [
                    'code' => LedgerService::OPERATING_EXPENSE,
                    'credit' => $locked->group_fee_amount,
                    'description' => 'Group-borne float charge reversed',
                ];
            }

            $journal = $this->ledger->post(
                $group,
                'Float purchase failed · '.$locked->reference.': '.$reason,
                $lines,
                now(),
                $locked,
                $locked->reference
            );

            $this->wallets->recordJournalMovement(
                $member,
                $locked->total_amount,
                'credit',
                'float_purchase_refund',
                $locked->reference,
                $journal,
                'M-Pesa float purchase refund'
            );

            $payload = $locked->payload ?? [];
            $payload['released_journal_id'] = $journal->id;

            if ($providerResponse !== []) {
                $payload['provider_response'] = $providerResponse;
            }

            $locked->update([
                'status' => FloatPurchase::STATUS_FAILED,
                'failure_reason' => $reason,
                'fulfilled_by' => $actor?->id,
                'payload' => $payload,
            ]);
        });

        $purchase->refresh();

        AuditService::log('float_purchase.failed', [
            'float_purchase_id' => $purchase->id,
            'reason' => $reason,
            'refunded' => true,
        ], $group);

        $this->notifications->send($member, 'float_purchase_failed', [
            'amount' => Money::format($purchase->amount, $purchase->currency),
            'agent_number' => $purchase->agent_number,
            'store_number' => $purchase->store_number,
            'phone' => $purchase->phone,
            'reference' => $purchase->reference,
            'reason' => $reason,
        ]);

        return $purchase;
    }

    public function refresh(FloatPurchase $purchase): FloatPurchase
    {
        $gateway = $this->gateways->configured();

        if ($purchase->isFinal() || ! $gateway->isConfigured() || ! $purchase->provider_reference) {
            return $purchase;
        }

        return $this->applyProviderResult($purchase, $gateway->query($purchase->provider_reference));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): FloatPurchase
    {
        $needle = (string) ($payload['provider_reference'] ?? $payload['reference'] ?? $payload['transaction_id'] ?? '');

        if ($needle === '') {
            throw new BusinessRuleException('A purchase reference is required.');
        }

        $purchase = FloatPurchase::withoutGlobalScopes()
            ->where(function ($query) use ($needle) {
                $query->where('reference', $needle)
                    ->orWhere('uuid', $needle)
                    ->orWhere('provider_reference', $needle);
            })
            ->latest()
            ->first();

        if (! $purchase) {
            throw new BusinessRuleException('The float purchase reference was not recognised.');
        }

        return $this->applyProviderResult($purchase, [
            'status' => (string) ($payload['status'] ?? 'processing'),
            'reference' => $payload['provider_reference'] ?? $payload['transaction_id'] ?? null,
            'response' => $payload,
        ]);
    }

    /**
     * Resolve either a registered agent/store or manually entered details.
     *
     * @param  array<string, mixed>  $data
     * @return array{agent_id:?int, agent_number:string, store_number:string, agent_name:?string, phone:string}
     */
    private function resolveAgent(Group $group, array $data): array
    {
        $agentId = $data['agent_id'] ?? null;

        if ($agentId) {
            $agent = MpesaAgent::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->whereKey($agentId)
                ->first();

            if (! $agent || $agent->status !== MpesaAgent::STATUS_ACTIVE) {
                throw new BusinessRuleException('The selected M-Pesa agent is not available.');
            }

            return [
                'agent_id' => $agent->id,
                'agent_number' => $agent->agent_number,
                'store_number' => $agent->store_number,
                'agent_name' => $agent->name,
                'phone' => $this->normalisePhone($agent->phone),
            ];
        }

        $agentNumber = trim((string) ($data['agent_number'] ?? ''));
        $storeNumber = trim((string) ($data['store_number'] ?? ''));

        if (! preg_match('/^\d{4,15}$/', $agentNumber)) {
            throw new BusinessRuleException('Enter a valid M-Pesa agent number (digits only).');
        }

        if (! preg_match('/^\d{4,15}$/', $storeNumber)) {
            throw new BusinessRuleException('Enter a valid M-Pesa store number (digits only).');
        }

        $agentName = trim((string) ($data['agent_name'] ?? ''));

        return [
            'agent_id' => null,
            'agent_number' => $agentNumber,
            'store_number' => $storeNumber,
            'agent_name' => $agentName !== '' ? $agentName : null,
            'phone' => $this->normalisePhone((string) ($data['phone'] ?? '')),
        ];
    }

    private function normalisePhone(string $phone): string
    {
        $normalised = KenyanPhone::normalise($phone);

        if ($normalised === null) {
            throw new BusinessRuleException('Enter a valid Kenyan phone number for the float recipient.');
        }

        return $normalised;
    }
}

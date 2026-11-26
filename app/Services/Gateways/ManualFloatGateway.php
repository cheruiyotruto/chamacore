<?php

namespace App\Services\Gateways;

use App\Contracts\FloatGateway;
use App\Exceptions\BusinessRuleException;

/**
 * Placeholder driver used when no airtime provider API is configured.
 *
 * Purchases stay "pending" after the wallet is debited so a treasurer can
 * recharge the number through the provider's own portal and mark the purchase
 * fulfilled (or fail it, which refunds the member automatically).
 */
class ManualFloatGateway implements FloatGateway
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function initiate(array $request): array
    {
        throw new BusinessRuleException('No airtime provider is configured.');
    }

    public function query(string $providerReference): array
    {
        return [
            'status' => 'pending',
            'reference' => $providerReference,
            'raw' => [],
        ];
    }
}

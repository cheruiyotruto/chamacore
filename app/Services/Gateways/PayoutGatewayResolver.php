<?php

namespace App\Services\Gateways;

use App\Contracts\PayoutGateway;
use InvalidArgumentException;

final class PayoutGatewayResolver
{
    public function __construct(
        private readonly BankTransferGateway $bank,
        private readonly MpesaPayoutGateway $mpesa,
    ) {
    }

    public function for(string $provider): PayoutGateway
    {
        return match ($provider) {
            'bank' => $this->bank,
            'mpesa' => $this->mpesa,
            default => throw new InvalidArgumentException("Unsupported payout provider [{$provider}]."),
        };
    }
}

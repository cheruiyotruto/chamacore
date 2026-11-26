<?php

namespace App\Services\Gateways;

use App\Contracts\FloatGateway;

final class FloatGatewayResolver
{
    public function __construct(
        private readonly ManualFloatGateway $manual,
        private readonly HttpFloatGateway $http,
    ) {
    }

    public function for(string $driver): FloatGateway
    {
        return match ($driver) {
            'manual' => $this->manual,
            'http' => $this->http,
            default => $this->manual,
        };
    }

    public function configured(): FloatGateway
    {
        return $this->for((string) config('services.float.driver', 'manual'));
    }
}

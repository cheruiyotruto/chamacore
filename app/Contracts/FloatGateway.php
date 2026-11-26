<?php

namespace App\Contracts;

interface FloatGateway
{
    /**
     * Send an airtime/float recharge request to the provider.
     *
     * @param  array<string, mixed>  $request
     * @return array{status:string, reference:?string, response:array}
     */
    public function initiate(array $request): array;

    /**
     * @return array{status:string, reference:?string, raw:array}
     */
    public function query(string $providerReference): array;

    public function isConfigured(): bool;
}

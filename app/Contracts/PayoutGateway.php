<?php

namespace App\Contracts;

interface PayoutGateway
{
    /**
     * Start a money-out instruction and return the provider reference.
     *
     * @param  array<string, mixed>  $request
     * @return array{reference:string, response:array}
     */
    public function initiate(array $request): array;

    /**
     * @return array{status:string, reference:?string, raw:array}
     */
    public function query(string $providerReference): array;

    public function isConfigured(): bool;
}

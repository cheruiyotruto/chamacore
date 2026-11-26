<?php

namespace App\Contracts;

interface PaymentGateway
{
    /**
     * Start a payment and return the provider reference.
     *
     * @param  array{amount:int, phone:string, reference:string, description:string, callback_url?:string}  $request
     * @return array{reference:string, response:array}
     */
    public function initiate(array $request): array;

    /**
     * Query the provider for the current state of a payment.
     *
     * @return array{status:string, amount:?int, reference:?string, raw:array}
     */
    public function query(string $providerReference): array;

    public function isConfigured(): bool;
}

<?php

namespace App\Contracts;

interface SmsGateway
{
    /**
     * @return array{success:bool, reference:?string, error:?string}
     */
    public function send(string $recipient, string $message): array;
}

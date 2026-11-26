<?php

namespace App\Contracts;

interface WhatsAppGateway
{
    /**
     * @return array{success:bool, reference:?string, error:?string}
     */
    public function send(string $recipient, string $message): array;
}

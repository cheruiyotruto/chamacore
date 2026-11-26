<?php

namespace App\Services\Gateways;

use App\Contracts\WhatsAppGateway;
use App\Support\LogRedaction;
use Illuminate\Support\Facades\Log;

/**
 * Development/log-only WhatsApp driver. Messages are recorded but no external
 * provider is contacted, mirroring the SMS log transport.
 */
class LogWhatsAppGateway implements WhatsAppGateway
{
    public function send(string $recipient, string $message): array
    {
        Log::info('whatsapp.dispatched', [
            'to' => LogRedaction::recipient($recipient),
            'length' => strlen($message),
        ]);

        return ['success' => true, 'reference' => 'log-'.now()->timestamp, 'error' => null];
    }
}

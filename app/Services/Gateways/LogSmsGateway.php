<?php

namespace App\Services\Gateways;

use App\Contracts\SmsGateway;
use App\Support\LogRedaction;
use Illuminate\Support\Facades\Log;

/**
 * Development/log-only SMS driver. It records the message but never pretends a
 * real carrier accepted it: the log transport is an explicit configuration.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $recipient, string $message): array
    {
        Log::info('sms.dispatched', [
            'to' => LogRedaction::recipient($recipient),
            'length' => strlen($message),
        ]);

        return ['success' => true, 'reference' => 'log-'.now()->timestamp, 'error' => null];
    }
}

<?php

namespace App\Services\Gateways;

use App\Contracts\PushGateway;
use App\Support\LogRedaction;
use Illuminate\Support\Facades\Log;

class LogPushGateway implements PushGateway
{
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        Log::info('push.dispatched', [
            'tokens' => count($tokens),
            'title' => LogRedaction::text($title),
            'body' => LogRedaction::text($body),
        ]);

        return ['success' => true, 'reference' => 'log-'.now()->timestamp, 'error' => null];
    }
}

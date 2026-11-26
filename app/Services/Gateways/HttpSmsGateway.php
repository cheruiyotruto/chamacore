<?php

namespace App\Services\Gateways;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HttpSmsGateway implements SmsGateway
{
    public function send(string $recipient, string $message): array
    {
        $endpoint = config('services.sms.endpoint');
        $apiKey = config('services.sms.api_key');

        if (! $endpoint || ! $apiKey) {
            return ['success' => false, 'reference' => null, 'error' => 'SMS gateway is not configured.'];
        }

        try {
            $response = Http::withHeaders([
                'apiKey' => $apiKey,
                'Accept' => 'application/json',
            ])->asForm()->timeout(20)->post($endpoint, [
                'username' => config('services.sms.sender_id'),
                'to' => $recipient,
                'message' => $message,
                'from' => config('services.sms.sender_id'),
            ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'reference' => null,
                    'error' => 'SMS provider returned HTTP '.$response->status(),
                ];
            }

            return [
                'success' => true,
                'reference' => (string) ($response->json('SMSMessageData.Message') ?? $response->header('X-Request-Id')),
                'error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('sms.failed', ['to' => $recipient, 'error' => $e->getMessage()]);

            return ['success' => false, 'reference' => null, 'error' => 'SMS delivery failed.'];
        }
    }
}

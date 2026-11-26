<?php

namespace App\Services\Gateways;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generic HTTP transport for a WhatsApp Business API provider. The endpoint is
 * expected to accept a JSON body containing "to", "from" and "message".
 */
class HttpWhatsAppGateway implements WhatsAppGateway
{
    public function send(string $recipient, string $message): array
    {
        $endpoint = config('services.whatsapp.endpoint');
        $apiKey = config('services.whatsapp.api_key');

        if (! $endpoint || ! $apiKey) {
            return ['success' => false, 'reference' => null, 'error' => 'WhatsApp gateway is not configured.'];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(20)
                ->post($endpoint, [
                    'to' => $recipient,
                    'from' => config('services.whatsapp.from'),
                    'message' => $message,
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'reference' => null,
                    'error' => 'WhatsApp provider returned HTTP '.$response->status(),
                ];
            }

            return [
                'success' => true,
                'reference' => (string) ($response->json('id') ?? $response->json('message_id') ?? $response->header('X-Request-Id')),
                'error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('whatsapp.failed', ['to' => $recipient, 'error' => $e->getMessage()]);

            return ['success' => false, 'reference' => null, 'error' => 'WhatsApp delivery failed.'];
        }
    }
}

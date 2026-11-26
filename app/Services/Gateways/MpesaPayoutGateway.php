<?php

namespace App\Services\Gateways;

use App\Contracts\PayoutGateway;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Safaricom Daraja money-out adapter.
 *
 * Supports B2B PayBill/BuyGoods payments to other organisations and B2C
 * payments to a phone number. It deliberately refuses to simulate a transfer
 * when the payout credentials are missing.
 */
class MpesaPayoutGateway implements PayoutGateway
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.mpesa.consumer_key')
            && config('services.mpesa.consumer_secret')
            && config('services.mpesa.b2b_shortcode')
            && config('services.mpesa.b2b_initiator')
            && config('services.mpesa.b2b_security_credential')
            && config('services.mpesa.b2b_result_url'));
    }

    public function initiate(array $request): array
    {
        $this->assertConfigured();

        $originator = substr($request['idempotency_key'], 0, 32);
        $recipient = $this->normaliseRecipient($request);

        $payload = [
            'OriginatorConversationID' => $originator,
            'InitiatorName' => config('services.mpesa.b2b_initiator'),
            'SecurityCredential' => config('services.mpesa.b2b_security_credential'),
            'CommandID' => $request['command_id'] ?? 'BusinessPayBill',
            'Amount' => (int) ceil($request['amount'] / 100),
            'PartyA' => config('services.mpesa.b2b_shortcode'),
            'PartyB' => $recipient,
            'RecipientIdentifierType' => $request['recipient_type'] ?? '4',
            'SenderIdentifierType' => '4',
            'Remarks' => substr($request['description'] ?: 'Bank transfer', 0, 100),
            'QueueTimeOutURL' => $request['queue_url'] ?: config('services.mpesa.b2b_queue_url'),
            'ResultURL' => $request['result_url'] ?: config('services.mpesa.b2b_result_url'),
            'AccountReference' => substr($request['reference'], 0, 12),
        ];

        if ($payload['QueueTimeOutURL'] === null || $payload['QueueTimeOutURL'] === '') {
            throw new BusinessRuleException('An M-Pesa payout queue timeout URL is required.');
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl().'/mpesa/b2b/v1/paymentrequest', $payload);

        if (! $response->successful()) {
            throw new BusinessRuleException('M-Pesa rejected the payout: '.$response->body());
        }

        $body = $response->json() ?? [];
        $reference = (string) ($body['OriginatorConversationID'] ?? $originator);

        return [
            'reference' => $reference,
            'response' => array_merge($body, ['ConversationID' => $body['ConversationID'] ?? null]),
        ];
    }

    public function query(string $providerReference): array
    {
        // Daraja B2B has no public status query endpoint. Result callbacks are
        // authoritative; a missing callback therefore stays "processing".
        return [
            'status' => 'processing',
            'reference' => $providerReference,
            'raw' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function normaliseRecipient(array $request): string
    {
        if (($request['recipient_type'] ?? '4') === '1') {
            $digits = preg_replace('/\D+/', '', (string) $request['phone']);
            $digits = ltrim((string) $digits, '0');

            if (strlen($digits) === 9) {
                $digits = '254'.$digits;
            }

            if (! str_starts_with($digits, '254') || strlen($digits) !== 12) {
                throw new BusinessRuleException('A valid Kenyan phone number is required for this payout.');
            }

            return $digits;
        }

        $shortcode = (string) ($request['paybill_number'] ?: $request['till_number'] ?: $request['shortcode']);

        if ($shortcode === '') {
            throw new BusinessRuleException('The destination has no PayBill or till number.');
        }

        return $shortcode;
    }

    private function accessToken(): string
    {
        return Cache::remember('mpesa.access_token', 3000, function () {
            $response = Http::withBasicAuth(
                config('services.mpesa.consumer_key'),
                config('services.mpesa.consumer_secret')
            )->timeout(30)->get($this->baseUrl().'/oauth/v1/generate?grant_type=client_credentials');

            if (! $response->successful()) {
                throw new BusinessRuleException('Unable to authenticate with M-Pesa.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function baseUrl(): string
    {
        return config('services.mpesa.environment') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new BusinessRuleException(
                'M-Pesa payouts are not configured. Add the B2B initiator, security credential and result URL.'
            );
        }
    }
}

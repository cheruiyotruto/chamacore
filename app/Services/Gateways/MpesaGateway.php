<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Safaricom M-Pesa Daraja STK push adapter.
 *
 * When credentials are absent the gateway refuses to start a payment. It never
 * simulates a successful charge, which would corrupt wallet and ledger balances.
 */
class MpesaGateway implements PaymentGateway
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.mpesa.consumer_key')
            && config('services.mpesa.consumer_secret')
            && config('services.mpesa.shortcode')
            && config('services.mpesa.passkey')
            && config('services.mpesa.callback_url'));
    }

    public function initiate(array $request): array
    {
        $this->assertConfigured();

        $timestamp = now()->format('YmdHis');
        $password = base64_encode(config('services.mpesa.shortcode').config('services.mpesa.passkey').$timestamp);
        $phone = $this->normalisePhone($request['phone']);
        $amount = (int) ceil($request['amount'] / 100);

        if ($amount < 1) {
            throw new BusinessRuleException('M-Pesa transactions must be at least KES 1.');
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl().'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => config('services.mpesa.shortcode'),
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $amount,
                'PartyA' => $phone,
                'PartyB' => config('services.mpesa.shortcode'),
                'PhoneNumber' => $phone,
                'CallBackURL' => $request['callback_url'] ?: config('services.mpesa.callback_url'),
                'AccountReference' => substr($request['reference'], 0, 12),
                'TransactionDesc' => substr($request['description'], 0, 100),
            ]);

        if (! $response->successful()) {
            throw new BusinessRuleException('M-Pesa rejected the payment request: '.$response->body());
        }

        $payload = $response->json();

        return [
            'reference' => (string) ($payload['CheckoutRequestID'] ?? ''),
            'response' => $payload,
        ];
    }

    public function query(string $providerReference): array
    {
        $this->assertConfigured();

        $timestamp = now()->format('YmdHis');
        $password = base64_encode(config('services.mpesa.shortcode').config('services.mpesa.passkey').$timestamp);

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl().'/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => config('services.mpesa.shortcode'),
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $providerReference,
            ]);

        $payload = $response->json() ?? [];
        $resultCode = (string) ($payload['ResultCode'] ?? '');

        return [
            'status' => $resultCode === '0' ? 'succeeded' : ($resultCode === '' ? 'processing' : 'failed'),
            'amount' => null,
            'reference' => $payload['MpesaReceiptNumber'] ?? null,
            'raw' => $payload,
        ];
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

    private function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $digits = ltrim((string) $digits, '0');

        if (Str::startsWith($digits, '254')) {
            return $digits;
        }

        if (strlen($digits) === 9) {
            return '254'.$digits;
        }

        throw new BusinessRuleException('A valid Kenyan phone number is required for M-Pesa.');
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new BusinessRuleException(
                'M-Pesa is not configured. Add the Daraja credentials before accepting mobile payments.'
            );
        }
    }
}

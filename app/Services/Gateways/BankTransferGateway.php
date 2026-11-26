<?php

namespace App\Services\Gateways;

use App\Contracts\PayoutGateway;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Http;

/**
 * Generic HTTP adapter for a bank's outbound transfer API.
 *
 * The exact endpoint is deployment-specific; credentials live in config so no
 * transfer is ever simulated when the integration is not configured.
 */
class BankTransferGateway implements PayoutGateway
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.bank_payouts.endpoint')
            && config('services.bank_payouts.token'));
    }

    public function initiate(array $request): array
    {
        $this->assertConfigured();

        $response = Http::withToken((string) config('services.bank_payouts.token'))
            ->acceptJson()
            ->timeout(30)
            ->withHeaders(['Idempotency-Key' => $request['idempotency_key']])
            ->post(rtrim((string) config('services.bank_payouts.endpoint'), '/').'/transfers', [
                'amount' => (int) ceil($request['amount'] / 100),
                'currency' => $request['currency'],
                'bank_name' => $request['bank_name'],
                'account_name' => $request['account_name'],
                'account_number' => $request['account_number'],
                'reference' => $request['reference'],
                'narration' => $request['description'],
                'callback_url' => $request['callback_url'] ?: config('services.bank_payouts.callback_url'),
            ]);

        if (! $response->successful()) {
            throw new BusinessRuleException('The bank rejected the transfer: '.$response->body());
        }

        $payload = $response->json() ?? [];

        return [
            'reference' => (string) ($payload['id'] ?? $payload['reference'] ?? $payload['transaction_id'] ?? ''),
            'response' => $payload,
        ];
    }

    public function query(string $providerReference): array
    {
        $this->assertConfigured();

        $response = Http::withToken((string) config('services.bank_payouts.token'))
            ->acceptJson()
            ->timeout(30)
            ->get(rtrim((string) config('services.bank_payouts.endpoint'), '/').'/transfers/'.rawurlencode($providerReference));

        $payload = $response->json() ?? [];
        $status = strtolower((string) ($payload['status'] ?? ''));

        return [
            'status' => match ($status) {
                'completed', 'success', 'succeeded' => 'succeeded',
                'failed', 'rejected', 'declined' => 'failed',
                default => 'processing',
            },
            'reference' => $payload['id'] ?? $payload['reference'] ?? null,
            'raw' => $payload,
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new BusinessRuleException(
                'Bank transfers are not configured. Add the bank payout endpoint and token before sending money.'
            );
        }
    }
}

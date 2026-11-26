<?php

namespace App\Services\Gateways;

use App\Contracts\FloatGateway;
use App\Exceptions\BusinessRuleException;
use App\Support\KenyanPhone;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Generic JSON M-Pesa agent float provider adapter.
 *
 * The provider receives the agent number and store number that identify the
 * float account, plus the agent's registered M-Pesa line. Amounts are sent in
 * major currency units and the provider returns a status plus its own reference.
 * Exact field names vary between providers, so common aliases are accepted.
 */
class HttpFloatGateway implements FloatGateway
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.float.endpoint') && config('services.float.token'));
    }

    public function initiate(array $request): array
    {
        $this->assertConfigured();

        $payload = [
            'reference' => (string) $request['reference'],
            'agent_number' => (string) ($request['agent_number'] ?? ''),
            'store_number' => (string) ($request['store_number'] ?? ''),
            'agent_name' => (string) ($request['agent_name'] ?? ''),
            'phone' => $this->normalisePhone((string) $request['phone']),
            'network' => (string) ($request['network'] ?: config('services.float.network')),
            'amount' => (int) ceil(((int) $request['amount']) / 100),
            'currency' => (string) ($request['currency'] ?: 'KES'),
            'callback_url' => $request['callback_url'] ?: config('services.float.callback_url'),
            'metadata' => [
                'purchase_uuid' => (string) $request['uuid'],
                'group_id' => (int) $request['group_id'],
            ],
        ];

        try {
            $response = Http::withToken((string) config('services.float.token'))
                ->acceptJson()
                ->timeout((int) config('services.float.timeout', 30))
                ->post((string) config('services.float.endpoint'), $payload);
        } catch (Throwable $e) {
            throw new BusinessRuleException('The airtime provider could not be reached: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new BusinessRuleException(
                'The airtime provider rejected the request: '.substr($response->body(), 0, 300)
            );
        }

        return $this->parse($response->json() ?? []);
    }

    public function query(string $providerReference): array
    {
        $this->assertConfigured();

        $endpoint = rtrim((string) config('services.float.endpoint'), '/').'/'.rawurlencode($providerReference);

        try {
            $response = Http::withToken((string) config('services.float.token'))
                ->acceptJson()
                ->timeout((int) config('services.float.timeout', 30))
                ->get($endpoint);
        } catch (Throwable $e) {
            throw new BusinessRuleException('The airtime provider could not be reached: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new BusinessRuleException(
                'The airtime provider status check failed: '.substr($response->body(), 0, 300)
            );
        }

        return $this->parse($response->json() ?? []);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status:string, reference:?string, response:array}
     */
    private function parse(array $body): array
    {
        $rawStatus = strtolower((string) ($body['status'] ?? $body['state'] ?? 'processing'));

        $status = match (true) {
            in_array($rawStatus, ['success', 'succeeded', 'completed', 'complete', 'delivered'], true) => 'completed',
            in_array($rawStatus, ['fail', 'failed', 'declined', 'reversed', 'error'], true) => 'failed',
            default => 'processing',
        };

        $reference = $body['provider_reference']
            ?? $body['transaction_id']
            ?? $body['transactionId']
            ?? $body['id']
            ?? null;

        return [
            'status' => $status,
            'reference' => $reference === null ? null : (string) $reference,
            'response' => $body,
        ];
    }

    private function normalisePhone(string $phone): string
    {
        $normalised = KenyanPhone::normalise($phone);

        if ($normalised === null) {
            throw new BusinessRuleException('Enter a valid Kenyan phone number for the float recipient.');
        }

        return $normalised;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new BusinessRuleException(
                'Airtime purchases are not configured. Add FLOAT_ENDPOINT and FLOAT_TOKEN.'
            );
        }
    }
}

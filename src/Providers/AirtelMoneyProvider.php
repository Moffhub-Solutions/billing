<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AirtelMoneyProvider extends BasePaymentProvider
{
    protected string $clientId;

    protected string $clientSecret;

    protected string $environment;

    protected string $callbackUrl;

    protected string $baseUrl;

    protected string $country;

    protected string $currency;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $environment = 'sandbox',
        string $callbackUrl = '',
        ?string $baseUrl = null,
        string $country = 'KE',
        string $currency = 'KES',
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
        $this->country = $country;
        $this->currency = $currency;
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $phone = $options['phone'] ?? null;

        if ($phone === null) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Phone number is required for Airtel Money collection.'],
            ];
        }

        $reference = $options['reference'] ?? 'PAY-'.uniqid();
        $token = $this->getAccessToken();

        $payload = [
            'reference' => $reference,
            'subscriber' => [
                'country' => $options['country'] ?? $this->country,
                'currency' => $currency ?: $this->currency,
                'msisdn' => $this->formatPhone($phone),
            ],
            'transaction' => [
                'amount' => (int) ceil($amount / 100),
                'country' => $options['country'] ?? $this->country,
                'currency' => $currency ?: $this->currency,
                'id' => $reference,
            ],
        ];

        $this->logRequest('POST', $this->baseUrl.'/merchant/v2/payments/', $payload);

        $response = Http::withToken($token)
            ->withHeaders(['X-Country' => $options['country'] ?? $this->country])
            ->post($this->baseUrl.'/merchant/v2/payments/', $payload);

        $data = $response->json() ?? [];

        $statusCode = $data['status']['code'] ?? null;
        $success = $statusCode === 'DP00800001001' || $statusCode === '200';

        return [
            'success' => $success,
            'provider_payment_id' => $data['data']['transaction']['id'] ?? $reference,
            'provider_reference' => $data['data']['transaction']['reference_id'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $phone = $options['phone'] ?? null;

        if ($phone === null) {
            return [
                'success' => false,
                'provider_refund_id' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Phone number is required for Airtel Money disbursement.'],
            ];
        }

        $reference = $options['reference'] ?? 'REF-'.uniqid();
        $token = $this->getAccessToken();

        $payload = [
            'payee' => [
                'msisdn' => $this->formatPhone($phone),
                'wallet_type' => 'NORMAL',
            ],
            'reference' => $reference,
            'pin' => $options['pin'] ?? '',
            'transaction' => [
                'amount' => $amount !== null ? (int) ceil($amount / 100) : 0,
                'id' => $reference,
                'type' => 'B2C',
            ],
        ];

        $this->logRequest('POST', $this->baseUrl.'/standard/v2/disbursements/', $payload);

        $response = Http::withToken($token)
            ->withHeaders(['X-Country' => $this->country])
            ->post($this->baseUrl.'/standard/v2/disbursements/', $payload);

        $data = $response->json() ?? [];

        $statusCode = $data['status']['code'] ?? null;
        $success = $statusCode === '200' || $statusCode === 'DP00800001001';

        return [
            'success' => $success,
            'provider_refund_id' => $data['data']['transaction']['id'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->withHeaders(['X-Country' => $this->country])
            ->get($this->baseUrl.'/standard/v2/payments/'.$providerPaymentId);

        $data = $response->json() ?? [];
        $status = $data['data']['transaction']['status'] ?? null;

        return match ($status) {
            'TS' => 'completed',
            'TF' => 'failed',
            'TA' => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        $transaction = $request->input('transaction');

        return $transaction !== null && isset($transaction['id']);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $transaction = $request->input('transaction', []);
        $status = $transaction['status_code'] ?? $transaction['status'] ?? null;

        $success = $status === 'TS' || $status === 'DP00800001001';
        $amount = isset($transaction['amount']) ? (int) ((float) $transaction['amount'] * 100) : null;

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => $transaction['id'] ?? null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => $amount,
            'currency' => $transaction['currency'] ?? $this->currency,
            'metadata' => [
                'airtel_money_id' => $transaction['airtel_money_id'] ?? null,
                'message' => $transaction['message'] ?? null,
                'phone' => $transaction['msisdn'] ?? null,
                'reference' => $transaction['reference'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    #[\Override]
    public function getName(): string
    {
        return 'airtel';
    }

    // ─── OAuth ─────────────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        $cacheKey = 'billing:airtel:access_token:'.$this->clientId;

        return Cache::remember($cacheKey, 3300, function (): string {
            $response = Http::post($this->baseUrl.'/auth/oauth2/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);

            return $response->json('access_token') ?? '';
        });
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://openapi.airtel.africa'
            : 'https://openapiuat.airtel.africa';
    }

    protected function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? $phone;

        // Remove country code prefix if present
        if (str_starts_with($phone, '254') && strlen($phone) === 12) {
            $phone = substr($phone, 3);
        }

        // Remove leading zero
        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = substr($phone, 1);
        }

        return $phone;
    }
}

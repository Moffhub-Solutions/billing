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
        $phone = $this->optionNullableString($options, 'phone');

        if ($phone === null) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Phone number is required for Airtel Money collection.'],
            ];
        }

        $reference = $this->optionString($options, 'reference', 'PAY-'.uniqid());
        $country = $this->optionString($options, 'country', $this->country);
        $effectiveCurrency = $currency !== '' ? $currency : $this->currency;
        $token = $this->getAccessToken();

        $payload = [
            'reference' => $reference,
            'subscriber' => [
                'country' => $country,
                'currency' => $effectiveCurrency,
                'msisdn' => $this->formatPhone($phone),
            ],
            'transaction' => [
                'amount' => (int) ceil($amount / 100),
                'country' => $country,
                'currency' => $effectiveCurrency,
                'id' => $reference,
            ],
        ];

        $this->logRequest('POST', $this->baseUrl.'/merchant/v2/payments/', $payload);

        $response = Http::withToken($token)
            ->withHeaders(['X-Country' => $country])
            ->post($this->baseUrl.'/merchant/v2/payments/', $payload);

        $data = $this->asArray($response->json());

        $statusCode = $this->jsonString($response, 'status.code', '');
        $success = $statusCode === 'DP00800001001' || $statusCode === '200';

        $providerPaymentId = $this->jsonNullableString($response, 'data.transaction.id') ?? $reference;

        return [
            'success' => $success,
            'provider_payment_id' => $providerPaymentId,
            'provider_reference' => $this->jsonNullableString($response, 'data.transaction.reference_id'),
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $phone = $this->optionNullableString($options, 'phone');

        if ($phone === null) {
            return [
                'success' => false,
                'provider_refund_id' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Phone number is required for Airtel Money disbursement.'],
            ];
        }

        $reference = $this->optionString($options, 'reference', 'REF-'.uniqid());
        $token = $this->getAccessToken();

        $payload = [
            'payee' => [
                'msisdn' => $this->formatPhone($phone),
                'wallet_type' => 'NORMAL',
            ],
            'reference' => $reference,
            'pin' => $this->optionString($options, 'pin'),
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

        $data = $this->asArray($response->json());

        $statusCode = $this->jsonString($response, 'status.code', '');
        $success = $statusCode === '200' || $statusCode === 'DP00800001001';

        return [
            'success' => $success,
            'provider_refund_id' => $this->jsonNullableString($response, 'data.transaction.id'),
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

        $status = $this->jsonNullableString($response, 'data.transaction.status');

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

        return is_array($transaction) && isset($transaction['id']);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $transactionRaw = $request->input('transaction', []);
        $transaction = is_array($transactionRaw) ? $transactionRaw : [];

        $statusCode = $transaction['status_code'] ?? null;
        $statusValue = $transaction['status'] ?? null;
        $status = $statusCode ?? $statusValue;

        $success = $status === 'TS' || $status === 'DP00800001001';
        $amountRaw = $transaction['amount'] ?? null;
        $amount = is_numeric($amountRaw) ? (int) ((float) $amountRaw * 100) : null;

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => isset($transaction['id']) && is_string($transaction['id']) ? $transaction['id'] : null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => $amount,
            'currency' => isset($transaction['currency']) && is_string($transaction['currency']) ? $transaction['currency'] : $this->currency,
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
        return $this->clientId !== '' && $this->clientSecret !== '';
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

        /** @var string $token */
        $token = Cache::remember($cacheKey, 3300, function (): string {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::post($this->baseUrl.'/auth/oauth2/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);

            $token = $response->json('access_token');

            return is_string($token) ? $token : '';
        });

        return $token;
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
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        $phone = is_string($cleaned) ? $cleaned : $phone;

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

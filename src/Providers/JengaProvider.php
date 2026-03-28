<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class JengaProvider extends BasePaymentProvider
{
    protected string $apiKey;

    protected string $merchantCode;

    protected string $consumerSecret;

    protected ?string $privateKeyPath;

    protected string $environment;

    protected string $callbackUrl;

    protected string $baseUrl;

    public function __construct(
        string $apiKey,
        string $merchantCode,
        string $consumerSecret,
        ?string $privateKeyPath = null,
        string $environment = 'sandbox',
        string $callbackUrl = '',
        ?string $baseUrl = null,
    ) {
        $this->apiKey = $apiKey;
        $this->merchantCode = $merchantCode;
        $this->consumerSecret = $consumerSecret;
        $this->privateKeyPath = $privateKeyPath;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $reference = $options['reference'] ?? 'PAY-'.uniqid();
        $token = $this->getAccessToken();
        $amountWhole = number_format($amount / 100, 2, '.', '');

        $payload = [
            'merchant' => [
                'code' => $this->merchantCode,
                'reference' => $reference,
            ],
            'payment' => [
                'amount' => $amountWhole,
                'currency' => $currency ?: 'KES',
                'description' => $options['description'] ?? 'Payment',
                'type' => $options['payment_type'] ?? 'mobile_money',
            ],
            'customer' => [
                'name' => $options['name'] ?? '',
                'email' => $options['email'] ?? '',
            ],
            'callback_url' => $options['callback_url'] ?? $this->callbackUrl,
        ];

        if (isset($options['phone'])) {
            $payload['customer']['phone'] = $this->formatPhone($options['phone']);
        }

        if (isset($options['account_number'])) {
            $payload['payment']['account_number'] = $options['account_number'];
            $payload['payment']['bank_code'] = $options['bank_code'] ?? '';
        }

        // Sign the request
        $signatureData = $this->merchantCode.$reference.$amountWhole;
        $signature = $this->signRequest($signatureData);

        $this->logRequest('POST', $this->baseUrl.'/v3-apis/transaction-api/v3.0/remittance', $payload);

        $response = Http::withToken($token)
            ->withHeaders([
                'signature' => $signature,
            ])
            ->post($this->baseUrl.'/v3-apis/transaction-api/v3.0/remittance', $payload);

        $data = $response->json() ?? [];

        $success = ($data['status'] ?? false) === true || ($data['code'] ?? '') === '0';

        return [
            'success' => $success,
            'provider_payment_id' => $data['transactionId'] ?? null,
            'provider_reference' => $data['reference'] ?? $reference,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $phone = $options['phone'] ?? null;
        $reference = $options['reference'] ?? 'REF-'.uniqid();
        $token = $this->getAccessToken();
        $amountWhole = $amount !== null ? number_format($amount / 100, 2, '.', '') : '0.00';

        $payload = [
            'source' => [
                'countryCode' => 'KE',
                'name' => $options['source_name'] ?? $this->merchantCode,
                'accountNumber' => $options['source_account'] ?? '',
            ],
            'destination' => [
                'type' => $phone !== null ? 'mobile' : 'bank',
                'countryCode' => 'KE',
                'name' => $options['recipient_name'] ?? '',
            ],
            'transfer' => [
                'type' => 'InternalFundsTransfer',
                'amount' => $amountWhole,
                'currencyCode' => 'KES',
                'reference' => $reference,
                'description' => $options['description'] ?? "Refund for {$providerPaymentId}",
            ],
        ];

        if ($phone !== null) {
            $payload['destination']['mobileNumber'] = $this->formatPhone($phone);
        }

        $signatureData = $reference.$amountWhole.$this->merchantCode;
        $signature = $this->signRequest($signatureData);

        $this->logRequest('POST', $this->baseUrl.'/v3-apis/transaction-api/v3.0/remittance', $payload);

        $response = Http::withToken($token)
            ->withHeaders(['signature' => $signature])
            ->post($this->baseUrl.'/v3-apis/transaction-api/v3.0/remittance', $payload);

        $data = $response->json() ?? [];

        $success = ($data['status'] ?? false) === true || ($data['code'] ?? '') === '0';

        return [
            'success' => $success,
            'provider_refund_id' => $data['transactionId'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get($this->baseUrl.'/v3-apis/transaction-api/v3.0/payments/'.$providerPaymentId);

        $data = $response->json() ?? [];
        $status = $data['status'] ?? null;

        return match ($status) {
            'completed', 'success', true => 'completed',
            'failed', 'error' => 'failed',
            'cancelled' => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        $signature = $request->header('X-Jenga-Signature', '');

        // If signature header is present and we have a key, verify cryptographically
        if (! empty($signature) && $this->privateKeyPath !== null && file_exists($this->privateKeyPath)) {
            $payload = $request->getContent();
            $publicKey = openssl_pkey_get_public(file_get_contents($this->privateKeyPath));

            if ($publicKey === false) {
                return false;
            }

            return openssl_verify($payload, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
        }

        // Fall back to structure verification — check for expected Jenga payload fields
        return $request->input('transactionId') !== null
            || $request->input('transaction_id') !== null;
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $data = $request->all();
        $status = $data['status'] ?? $data['transaction_status'] ?? null;

        $success = $status === 'completed' || $status === 'success' || $status === true;
        $amount = isset($data['amount']) ? (int) ((float) $data['amount'] * 100) : null;

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => $data['transactionId'] ?? $data['transaction_id'] ?? null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => $amount,
            'currency' => $data['currency'] ?? $data['currencyCode'] ?? 'KES',
            'metadata' => [
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'payment_type' => $data['payment_type'] ?? $data['type'] ?? null,
                'receipt' => $data['receipt'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->consumerSecret);
    }

    #[\Override]
    public function getName(): string
    {
        return 'jenga';
    }

    // ─── OAuth ─────────────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        $cacheKey = 'billing:jenga:access_token:'.$this->merchantCode;

        return Cache::remember($cacheKey, 3300, function (): string {
            $credentials = base64_encode($this->apiKey.':'.$this->consumerSecret);

            $response = Http::withHeaders([
                'Authorization' => 'Basic '.$credentials,
            ])->post($this->baseUrl.'/authenticate/merchant', [
                'merchantCode' => $this->merchantCode,
                'consumerSecret' => $this->consumerSecret,
            ]);

            return $response->json('accessToken') ?? $response->json('access_token') ?? '';
        });
    }

    // ─── Request Signing ───────────────────────────────────────────────

    protected function signRequest(string $data): string
    {
        if ($this->privateKeyPath === null || ! file_exists($this->privateKeyPath)) {
            return '';
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($this->privateKeyPath));

        if ($privateKey === false) {
            return '';
        }

        $signature = '';
        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://api.jengahq.io'
            : 'https://sandbox.jengahq.io';
    }

    protected function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? $phone;

        if (str_starts_with($phone, '0')) {
            $phone = '254'.substr($phone, 1);
        }

        if (str_starts_with($phone, '7') && strlen($phone) === 9) {
            $phone = '254'.$phone;
        }

        return $phone;
    }
}

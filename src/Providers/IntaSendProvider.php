<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class IntaSendProvider extends BasePaymentProvider
{
    protected string $publishableKey;

    protected string $secretKey;

    protected string $environment;

    protected string $callbackUrl;

    protected string $baseUrl;

    public function __construct(
        string $publishableKey,
        string $secretKey,
        string $environment = 'sandbox',
        string $callbackUrl = '',
        ?string $baseUrl = null,
    ) {
        $this->publishableKey = $publishableKey;
        $this->secretKey = $secretKey;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $method = $options['method'] ?? 'checkout'; // checkout, stk_push

        if ($method === 'stk_push') {
            return $this->stkPush($amount, $currency, $options);
        }

        return $this->checkout($amount, $currency, $options);
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
                'metadata' => ['error' => 'Phone number is required for IntaSend disbursement.'],
            ];
        }

        $payload = [
            'phone_number' => $this->formatPhone($phone),
            'amount' => $amount !== null ? (int) ceil($amount / 100) : 0,
            'currency' => 'KES',
            'narrative' => $options['narrative'] ?? "Refund for {$providerPaymentId}",
        ];

        if (isset($options['name'])) {
            $payload['name'] = $options['name'];
        }

        $this->logRequest('POST', $this->baseUrl.'/api/v1/send-money/mpesa/', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/api/v1/send-money/mpesa/', $payload);

        $data = $response->json() ?? [];

        $success = ($data['status'] ?? '') === 'Preview' || ($data['tracking_id'] ?? null) !== null;

        return [
            'success' => $success,
            'provider_refund_id' => $data['tracking_id'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl.'/api/v1/payment/status/', [
                'invoice_id' => $providerPaymentId,
            ]);

        $data = $response->json() ?? [];
        $statusCode = $data['invoice']['state'] ?? $data['status_code'] ?? null;

        return $this->mapStatus($statusCode);
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        $signature = $request->header('X-IntaSend-Signature', '');

        if (! empty($signature) && ! empty($this->secretKey)) {
            $payload = $request->getContent();
            $expected = hash_hmac('sha256', $payload, $this->secretKey);

            return hash_equals($expected, $signature);
        }

        // Fall back to structure verification
        return $request->has('invoice_id') || $request->has('tracking_id');
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $data = $request->all();
        $invoice = $data['invoice'] ?? $data;
        $statusCode = $invoice['state'] ?? $invoice['status_code'] ?? $data['status_code'] ?? null;
        $status = $this->mapStatus($statusCode);

        $amount = isset($invoice['net_amount'])
            ? (int) ((float) $invoice['net_amount'] * 100)
            : (isset($invoice['amount']) ? (int) ((float) $invoice['amount'] * 100) : null);

        return [
            'event' => $status === 'completed' ? 'payment.completed' : ($status === 'failed' ? 'payment.failed' : 'payment.pending'),
            'provider_payment_id' => $invoice['invoice_id'] ?? $data['invoice_id'] ?? $data['tracking_id'] ?? null,
            'status' => $status,
            'amount' => $amount,
            'currency' => $invoice['currency'] ?? $data['currency'] ?? 'KES',
            'metadata' => [
                'api_ref' => $invoice['api_ref'] ?? $data['api_ref'] ?? null,
                'mpesa_reference' => $invoice['mpesa_reference'] ?? $data['mpesa_reference'] ?? null,
                'status_code' => $statusCode,
                'challenge' => $data['challenge'] ?? null,
                'phone' => $invoice['account'] ?? $data['phone_number'] ?? null,
                'failed_reason' => $invoice['failed_reason'] ?? $data['failed_reason'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return ! empty($this->publishableKey) && ! empty($this->secretKey);
    }

    #[\Override]
    public function getName(): string
    {
        return 'intasend';
    }

    // ─── Checkout (hosted payment page) ────────────────────────────────

    protected function checkout(int $amount, string $currency, array $options): array
    {
        $payload = [
            'amount' => (int) ceil($amount / 100),
            'currency' => $currency ?: 'KES',
            'api_ref' => $options['reference'] ?? 'PAY-'.uniqid(),
            'redirect_url' => $options['redirect_url'] ?? $this->callbackUrl,
            'webhook_url' => $options['webhook_url'] ?? $this->callbackUrl,
        ];

        if (isset($options['email']) || isset($options['first_name'])) {
            $payload['first_name'] = $options['first_name'] ?? '';
            $payload['last_name'] = $options['last_name'] ?? '';
            $payload['email'] = $options['email'] ?? '';
            $payload['country'] = $options['country'] ?? 'KE';
        }

        if (isset($options['phone'])) {
            $payload['phone_number'] = $this->formatPhone($options['phone']);
        }

        $this->logRequest('POST', $this->baseUrl.'/api/v1/checkout/', $payload);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->secretKey,
            'X-IntaSend-Public-API-Key' => $this->publishableKey,
        ])->post($this->baseUrl.'/api/v1/checkout/', $payload);

        $data = $response->json() ?? [];

        $success = isset($data['id']) || isset($data['url']);

        return [
            'success' => $success,
            'provider_payment_id' => $data['id'] ?? $data['invoice_id'] ?? null,
            'provider_reference' => $data['api_ref'] ?? $payload['api_ref'],
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    // ─── M-Pesa STK Push ───────────────────────────────────────────────

    protected function stkPush(int $amount, string $currency, array $options): array
    {
        $phone = $options['phone'] ?? null;

        if ($phone === null) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Phone number is required for STK Push.'],
            ];
        }

        $payload = [
            'phone_number' => $this->formatPhone($phone),
            'amount' => (int) ceil($amount / 100),
            'currency' => $currency ?: 'KES',
            'api_ref' => $options['reference'] ?? 'PAY-'.uniqid(),
            'wallet_id' => $options['wallet_id'] ?? null,
        ];

        $this->logRequest('POST', $this->baseUrl.'/api/v1/payment/mpesa-stk-push/', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/api/v1/payment/mpesa-stk-push/', $payload);

        $data = $response->json() ?? [];

        $success = isset($data['invoice']) && ($data['invoice']['state'] ?? '') !== 'FAILED';

        return [
            'success' => $success,
            'provider_payment_id' => $data['invoice']['invoice_id'] ?? null,
            'provider_reference' => $data['invoice']['api_ref'] ?? $payload['api_ref'],
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://payment.intasend.com'
            : 'https://sandbox.intasend.com';
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

    protected function mapStatus(?string $statusCode): string
    {
        return match ($statusCode) {
            'TS100', 'COMPLETE', 'SUCCESSFUL', 'successful' => 'completed',
            'TF103', 'TF106', 'FAILED', 'failed' => 'failed',
            'TC108', 'CANCELLED', 'cancelled' => 'cancelled',
            default => 'pending',
        };
    }
}

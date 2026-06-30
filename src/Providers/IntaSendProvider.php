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
        $method = $this->optionString($options, 'method', 'checkout'); // checkout, stk_push

        if ($method === 'stk_push') {
            return $this->stkPush($amount, $currency, $options);
        }

        return $this->checkout($amount, $currency, $options);
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
                'metadata' => ['error' => 'Phone number is required for IntaSend disbursement.'],
            ];
        }

        $payload = [
            'phone_number' => $this->formatPhone($phone),
            'amount' => $amount !== null ? (int) ceil($amount / 100) : 0,
            'currency' => 'KES',
            'narrative' => $this->optionString($options, 'narrative', "Refund for {$providerPaymentId}"),
        ];

        $name = $this->optionNullableString($options, 'name');
        if ($name !== null) {
            $payload['name'] = $name;
        }

        $this->logRequest('POST', $this->baseUrl.'/api/v1/send-money/mpesa/', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/api/v1/send-money/mpesa/', $payload);

        $data = $this->asArray($response->json());

        $trackingId = $data['tracking_id'] ?? null;
        $success = ($data['status'] ?? '') === 'Preview' || $trackingId !== null;

        return [
            'success' => $success,
            'provider_refund_id' => is_string($trackingId) ? $trackingId : null,
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

        $data = $this->asArray($response->json());
        $invoice = $data['invoice'] ?? null;
        $statusCode = (is_array($invoice) ? ($invoice['state'] ?? null) : null) ?? $data['status_code'] ?? null;

        return $this->mapStatus(is_string($statusCode) ? $statusCode : null);
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        // IntaSend webhooks carry a configurable "challenge" value, not an HMAC.
        // When a challenge is configured (as the provider's secretKey here) it
        // must match exactly; otherwise the callback is reported "unverified" and
        // WebhookController confirms via the IntaSend status API (re-query). The
        // structure-only fallback is removed so a forged body can't self-certify.
        if ($this->secretKey === '') {
            return false;
        }

        $challengeRaw = $request->input('challenge');
        $challenge = is_string($challengeRaw) ? $challengeRaw : '';

        return $challenge !== '' && hash_equals($this->secretKey, $challenge);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $data = $request->all();
        $invoiceRaw = $data['invoice'] ?? $data;
        $invoice = is_array($invoiceRaw) ? $invoiceRaw : [];

        $statusCode = $invoice['state'] ?? $invoice['status_code'] ?? $data['status_code'] ?? null;
        $status = $this->mapStatus(is_string($statusCode) ? $statusCode : null);

        $netAmount = $invoice['net_amount'] ?? null;
        $invoiceAmount = $invoice['amount'] ?? null;
        $amount = is_numeric($netAmount)
            ? (int) ((float) $netAmount * 100)
            : (is_numeric($invoiceAmount) ? (int) ((float) $invoiceAmount * 100) : null);

        $providerPaymentId = $invoice['invoice_id'] ?? $data['invoice_id'] ?? $data['tracking_id'] ?? null;

        return [
            'event' => $status === 'completed' ? 'payment.completed' : ($status === 'failed' ? 'payment.failed' : 'payment.pending'),
            'provider_payment_id' => is_string($providerPaymentId) ? $providerPaymentId : null,
            'status' => $status,
            'amount' => $amount,
            'currency' => $this->stringOr($invoice['currency'] ?? $data['currency'] ?? null, 'KES'),
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
        return $this->publishableKey !== '' && $this->secretKey !== '';
    }

    #[\Override]
    public function getName(): string
    {
        return 'intasend';
    }

    // ─── Checkout (hosted payment page) ────────────────────────────────

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    protected function checkout(int $amount, string $currency, array $options): array
    {
        $apiRef = $this->optionString($options, 'reference', 'PAY-'.uniqid());

        $payload = [
            'amount' => (int) ceil($amount / 100),
            'currency' => $currency !== '' ? $currency : 'KES',
            'api_ref' => $apiRef,
            'redirect_url' => $this->optionString($options, 'redirect_url', $this->callbackUrl),
            'webhook_url' => $this->optionString($options, 'webhook_url', $this->callbackUrl),
        ];

        if (isset($options['email']) || isset($options['first_name'])) {
            $payload['first_name'] = $this->optionString($options, 'first_name');
            $payload['last_name'] = $this->optionString($options, 'last_name');
            $payload['email'] = $this->optionString($options, 'email');
            $payload['country'] = $this->optionString($options, 'country', 'KE');
        }

        $phone = $this->optionNullableString($options, 'phone');
        if ($phone !== null) {
            $payload['phone_number'] = $this->formatPhone($phone);
        }

        $this->logRequest('POST', $this->baseUrl.'/api/v1/checkout/', $payload);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->secretKey,
            'X-IntaSend-Public-API-Key' => $this->publishableKey,
        ])->post($this->baseUrl.'/api/v1/checkout/', $payload);

        $data = $this->asArray($response->json());

        $success = isset($data['id']) || isset($data['url']);

        $providerPaymentId = $data['id'] ?? $data['invoice_id'] ?? null;
        $providerReference = $data['api_ref'] ?? $apiRef;

        return [
            'success' => $success,
            'provider_payment_id' => is_string($providerPaymentId) ? $providerPaymentId : null,
            'provider_reference' => is_string($providerReference) ? $providerReference : null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    // ─── M-Pesa STK Push ───────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    protected function stkPush(int $amount, string $currency, array $options): array
    {
        $phone = $this->optionNullableString($options, 'phone');

        if ($phone === null) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Phone number is required for STK Push.'],
            ];
        }

        $apiRef = $this->optionString($options, 'reference', 'PAY-'.uniqid());

        $payload = [
            'phone_number' => $this->formatPhone($phone),
            'amount' => (int) ceil($amount / 100),
            'currency' => $currency !== '' ? $currency : 'KES',
            'api_ref' => $apiRef,
            'wallet_id' => $this->optionNullableString($options, 'wallet_id'),
        ];

        $this->logRequest('POST', $this->baseUrl.'/api/v1/payment/mpesa-stk-push/', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/api/v1/payment/mpesa-stk-push/', $payload);

        $data = $this->asArray($response->json());

        $invoice = $data['invoice'] ?? null;
        $invoiceArr = is_array($invoice) ? $invoice : [];
        $success = $invoiceArr !== [] && ($invoiceArr['state'] ?? '') !== 'FAILED';

        $providerPaymentId = $invoiceArr['invoice_id'] ?? null;
        $providerReference = $invoiceArr['api_ref'] ?? $apiRef;

        return [
            'success' => $success,
            'provider_payment_id' => is_string($providerPaymentId) ? $providerPaymentId : null,
            'provider_reference' => is_string($providerReference) ? $providerReference : null,
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
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        $phone = is_string($cleaned) ? $cleaned : $phone;

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

    protected function stringOr(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }
}

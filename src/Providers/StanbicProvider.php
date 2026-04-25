<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StanbicProvider extends BasePaymentProvider
{
    protected string $apiKey;

    protected string $apiSecret;

    protected string $environment;

    protected string $callbackUrl;

    protected string $baseUrl;

    protected string $merchantCode;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $environment = 'sandbox',
        string $callbackUrl = '',
        ?string $baseUrl = null,
        string $merchantCode = '',
    ) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
        $this->merchantCode = $merchantCode;
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $reference = $this->optionString($options, 'reference', 'PAY-'.uniqid());
        $paymentMethod = $this->optionString($options, 'payment_method', 'stk_push'); // stk_push, mobile_money, bank_transfer

        $payload = [
            'merchant_code' => $this->merchantCode,
            'reference' => $reference,
            'amount' => (int) ceil($amount / 100),
            'currency' => $currency !== '' ? $currency : 'KES',
            'payment_method' => $paymentMethod,
            'callback_url' => $this->optionString($options, 'callback_url', $this->callbackUrl),
            'description' => $this->optionString($options, 'description', 'Payment'),
        ];

        $phone = $this->optionNullableString($options, 'phone');
        if ($phone !== null) {
            $payload['phone_number'] = $this->formatPhone($phone);
        }

        $accountNumber = $this->optionNullableString($options, 'account_number');
        if ($accountNumber !== null) {
            $payload['account_number'] = $accountNumber;
        }

        $bankCode = $this->optionNullableString($options, 'bank_code');
        if ($bankCode !== null) {
            $payload['bank_code'] = $bankCode;
        }

        $endpoint = match ($paymentMethod) {
            'stk_push' => '/payments/stk-push',
            'mobile_money' => '/payments/mobile-money',
            'bank_transfer' => '/payments/bank-transfer',
            default => '/payments/initiate',
        };

        $this->logRequest('POST', $this->baseUrl.$endpoint, $payload);

        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->post($this->baseUrl.$endpoint, $payload);

        $data = $this->asArray($response->json());

        $success = ($data['status'] ?? '') === 'success' || ($data['response_code'] ?? '') === '00';

        return [
            'success' => $success,
            'provider_payment_id' => isset($data['transaction_id']) && is_string($data['transaction_id']) ? $data['transaction_id'] : null,
            'provider_reference' => isset($data['reference']) && is_string($data['reference']) ? $data['reference'] : $reference,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $payload = [
            'merchant_code' => $this->merchantCode,
            'transaction_id' => $providerPaymentId,
            'amount' => $amount !== null ? (int) ceil($amount / 100) : null,
            'reason' => $this->optionString($options, 'reason', 'Refund'),
            'callback_url' => $this->optionString($options, 'callback_url', $this->callbackUrl),
        ];

        $phone = $this->optionNullableString($options, 'phone');
        if ($phone !== null) {
            $payload['phone_number'] = $this->formatPhone($phone);
        }

        $this->logRequest('POST', $this->baseUrl.'/payments/refund', $payload);

        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->post($this->baseUrl.'/payments/refund', $payload);

        $data = $this->asArray($response->json());

        $success = ($data['status'] ?? '') === 'success' || ($data['response_code'] ?? '') === '00';

        $providerRefundId = $data['refund_id'] ?? $data['transaction_id'] ?? null;

        return [
            'success' => $success,
            'provider_refund_id' => is_string($providerRefundId) ? $providerRefundId : null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->get($this->baseUrl.'/payments/status/'.$providerPaymentId);

        $data = $this->asArray($response->json());
        $status = $data['transaction_status'] ?? $data['status'] ?? null;

        return match ($status) {
            'completed', 'success', '00' => 'completed',
            'failed', 'error' => 'failed',
            'cancelled', 'reversed' => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        $signature = (string) $request->header('X-Stanbic-Signature', '');
        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $this->apiSecret);

        return hash_equals($expected, $signature);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $data = $request->all();
        $status = $data['transaction_status'] ?? $data['status'] ?? 'unknown';
        $success = in_array($status, ['completed', 'success', '00'], true);

        $amountValue = $data['amount'] ?? null;
        $amount = is_numeric($amountValue) ? (int) ((float) $amountValue * 100) : null;

        $providerPaymentId = $data['transaction_id'] ?? null;

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => is_string($providerPaymentId) ? $providerPaymentId : null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => $amount,
            'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'KES',
            'metadata' => [
                'reference' => $data['reference'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'receipt_number' => $data['receipt_number'] ?? null,
                'merchant_code' => $data['merchant_code'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    #[\Override]
    public function getName(): string
    {
        return 'stanbic';
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://api.stanbicbank.co.ke/v1'
            : 'https://sandbox.stanbicbank.co.ke/v1';
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
}

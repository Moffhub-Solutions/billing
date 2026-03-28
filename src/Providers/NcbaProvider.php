<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class NcbaProvider extends BasePaymentProvider
{
    protected string $apiKey;

    protected string $apiSecret;

    protected string $environment;

    protected string $callbackUrl;

    protected string $baseUrl;

    public function __construct(
        string $apiKey,
        string $apiSecret = '',
        string $environment = 'sandbox',
        string $callbackUrl = '',
        ?string $baseUrl = null,
    ) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $reference = $options['reference'] ?? 'PAY-'.uniqid();
        $transferType = $options['transfer_type'] ?? 'pesalink'; // pesalink, internal

        $payload = [
            'reference' => $reference,
            'amount' => number_format($amount / 100, 2, '.', ''),
            'currency' => $currency ?: 'KES',
            'description' => $options['description'] ?? 'Payment',
            'destination_account' => $options['destination_account'] ?? '',
            'transfer_type' => $transferType,
            'callback_url' => $options['callback_url'] ?? $this->callbackUrl,
        ];

        if (isset($options['bank_code'])) {
            $payload['bank_code'] = $options['bank_code'];
        }

        if (isset($options['phone'])) {
            $payload['phone'] = $this->formatPhone($options['phone']);
        }

        if (isset($options['recipient_name'])) {
            $payload['recipient_name'] = $options['recipient_name'];
        }

        $this->logRequest('POST', $this->baseUrl.'/payments/transfer', $payload);

        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->post($this->baseUrl.'/payments/transfer', $payload);

        $data = $response->json() ?? [];

        $success = ($data['status'] ?? '') === 'success' || ($data['response_code'] ?? '') === '00';

        return [
            'success' => $success,
            'provider_payment_id' => $data['transaction_id'] ?? null,
            'provider_reference' => $data['reference'] ?? $reference,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $payload = [
            'transaction_id' => $providerPaymentId,
            'amount' => $amount !== null ? number_format($amount / 100, 2, '.', '') : null,
            'reason' => $options['reason'] ?? 'Refund',
            'destination_account' => $options['destination_account'] ?? '',
            'callback_url' => $options['callback_url'] ?? $this->callbackUrl,
        ];

        $this->logRequest('POST', $this->baseUrl.'/payments/refund', $payload);

        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->post($this->baseUrl.'/payments/refund', $payload);

        $data = $response->json() ?? [];

        $success = ($data['status'] ?? '') === 'success' || ($data['response_code'] ?? '') === '00';

        return [
            'success' => $success,
            'provider_refund_id' => $data['refund_id'] ?? $data['transaction_id'] ?? null,
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

        $data = $response->json() ?? [];
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
        // NCBA IPN Push - verify via signature if available, else structure check
        $signature = $request->header('X-NCBA-Signature', '');

        if (! empty($signature) && ! empty($this->apiSecret)) {
            $payload = $request->getContent();
            $expected = hash_hmac('sha256', $payload, $this->apiSecret);

            return hash_equals($expected, $signature);
        }

        // Fall back to structure verification
        return $request->has('transaction_id')
            || $request->has('transactionId')
            || $request->has('reference');
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $data = $request->all();
        $status = $data['transaction_status'] ?? $data['status'] ?? 'unknown';
        $success = in_array($status, ['completed', 'success', '00'], true);

        $amount = null;
        if (isset($data['amount'])) {
            $amount = (int) ((float) $data['amount'] * 100);
        } elseif (isset($data['transactionAmount'])) {
            $amount = (int) ((float) $data['transactionAmount'] * 100);
        }

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => $data['transaction_id'] ?? $data['transactionId'] ?? null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'KES',
            'metadata' => [
                'reference' => $data['reference'] ?? null,
                'phone' => $data['phone'] ?? $data['msisdn'] ?? null,
                'customer_name' => $data['customer_name'] ?? $data['customerName'] ?? null,
                'narration' => $data['narration'] ?? null,
                'receipt_number' => $data['receipt_number'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    #[\Override]
    public function getName(): string
    {
        return 'ncba';
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://api.ncbagroup.com/v1'
            : 'https://sandbox.ncbagroup.com/v1';
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

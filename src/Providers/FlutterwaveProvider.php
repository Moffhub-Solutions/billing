<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FlutterwaveProvider extends BasePaymentProvider
{
    public function __construct(
        protected string $secretKey,
        protected string $publicKey = '',
        protected string $encryptionKey = '',
        protected string $webhookSecret = '',
        protected string $baseUrl = 'https://api.flutterwave.com/v3',
    ) {}

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $email = $options['email'] ?? null;

        if ($email === null) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Email is required for Flutterwave.'],
            ];
        }

        return $this->initializePayment($email, $amount, $currency, $options);
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $payload = [];

        if ($amount !== null) {
            $payload['amount'] = $amount / 100; // Flutterwave refunds in whole units
        }

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl."/transactions/{$providerPaymentId}/refund", $payload);

        $data = $response->json();
        $success = ($data['status'] ?? '') === 'success';

        return [
            'success' => $success,
            'provider_refund_id' => $data['data']['id'] ?? null,
            'status' => $success ? 'completed' : 'failed',
            'metadata' => $data['data'] ?? $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl."/transactions/{$providerPaymentId}/verify");

        $data = $response->json();

        return match ($data['data']['status'] ?? 'unknown') {
            'successful' => 'completed',
            'failed' => 'failed',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        if (empty($this->webhookSecret)) {
            return false;
        }

        $signature = $request->header('verif-hash', '');

        return hash_equals($this->webhookSecret, $signature);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $event = $request->input('event', '');
        $data = $request->input('data', []);

        return [
            'event' => match ($event) {
                'charge.completed' => 'payment.completed',
                'charge.failed' => 'payment.failed',
                default => $event,
            },
            'provider_payment_id' => (string) ($data['id'] ?? ''),
            'status' => match ($data['status'] ?? 'unknown') {
                'successful' => 'completed',
                'failed' => 'failed',
                default => 'pending',
            },
            'amount' => isset($data['amount']) ? (int) ($data['amount'] * 100) : null,
            'currency' => $data['currency'] ?? null,
            'metadata' => [
                'flw_event' => $event,
                'flw_ref' => $data['flw_ref'] ?? null,
                'tx_ref' => $data['tx_ref'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'customer' => $data['customer'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return ! empty($this->secretKey);
    }

    #[\Override]
    public function getName(): string
    {
        return 'flutterwave';
    }

    // ─── Flutterwave-specific ──────────────────────────────────────────

    /**
     * Initialize a standard payment (returns redirect URL).
     */
    public function initializePayment(string $email, int $amount, string $currency, array $options = []): array
    {
        $payload = [
            'tx_ref' => $options['reference'] ?? 'FLW-'.uniqid(),
            'amount' => $amount / 100, // Flutterwave expects whole units
            'currency' => $currency,
            'redirect_url' => $options['callback_url'] ?? '',
            'customer' => [
                'email' => $email,
                'name' => $options['name'] ?? null,
                'phonenumber' => $options['phone'] ?? null,
            ],
            'meta' => $options['metadata'] ?? null,
            'customizations' => [
                'title' => $options['title'] ?? 'Payment',
                'description' => $options['description'] ?? 'Payment',
            ],
        ];

        // Restrict to specific payment methods if specified
        if (isset($options['payment_options'])) {
            $payload['payment_options'] = $options['payment_options']; // e.g., "mpesa,card"
        }

        $this->logRequest('POST', $this->baseUrl.'/payments', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/payments', $payload);

        $data = $response->json();
        $success = ($data['status'] ?? '') === 'success';

        return [
            'success' => $success,
            'provider_payment_id' => $payload['tx_ref'],
            'provider_reference' => null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => [
                'payment_link' => $data['data']['link'] ?? null,
                ...$data['data'] ?? [],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaystackProvider extends BasePaymentProvider
{
    public function __construct(
        protected string $secretKey,
        protected string $publicKey = '',
        protected string $webhookSecret = '',
        protected string $baseUrl = 'https://api.paystack.co',
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
                'metadata' => ['error' => 'Email is required for Paystack.'],
            ];
        }

        // If authorization_code is provided, charge the saved card
        if (isset($options['authorization_code'])) {
            return $this->chargeAuthorization($email, $amount, $currency, $options['authorization_code'], $options);
        }

        // Otherwise, initialize a new transaction (returns redirect URL)
        return $this->initializeTransaction($email, $amount, $currency, $options);
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $payload = ['transaction' => $providerPaymentId];

        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        if (isset($options['reason'])) {
            $payload['merchant_note'] = $options['reason'];
        }

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/refund', $payload);

        $data = $response->json();
        $success = ($data['status'] ?? false) === true;

        return [
            'success' => $success,
            'provider_refund_id' => $data['data']['transaction']['id'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data['data'] ?? $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl.'/transaction/verify/'.$providerPaymentId);

        $data = $response->json();

        return match ($data['data']['status'] ?? 'unknown') {
            'success' => 'completed',
            'failed' => 'failed',
            'abandoned' => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        if (empty($this->webhookSecret)) {
            return false;
        }

        $signature = $request->header('x-paystack-signature', '');
        $payload = $request->getContent();
        $expected = hash_hmac('sha512', $payload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $event = $request->input('event', '');
        $data = $request->input('data', []);

        $status = match ($event) {
            'charge.success' => 'completed',
            'charge.failed' => 'failed',
            'refund.processed' => 'refunded',
            default => 'pending',
        };

        return [
            'event' => match ($event) {
                'charge.success' => 'payment.completed',
                'charge.failed' => 'payment.failed',
                'refund.processed' => 'payment.refunded',
                default => $event,
            },
            'provider_payment_id' => $data['reference'] ?? null,
            'status' => $status,
            'amount' => $data['amount'] ?? null, // Paystack amounts are already in kobo/cents
            'currency' => $data['currency'] ?? null,
            'metadata' => [
                'paystack_event' => $event,
                'channel' => $data['channel'] ?? null,
                'authorization' => $data['authorization'] ?? null,
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
        return 'paystack';
    }

    // ─── Paystack-specific methods ─────────────────────────────────────

    /**
     * Initialize a transaction (returns a redirect URL for the customer).
     */
    public function initializeTransaction(string $email, int $amount, string $currency, array $options = []): array
    {
        $payload = [
            'email' => $email,
            'amount' => $amount, // in kobo/cents
            'currency' => $currency,
            'reference' => $options['reference'] ?? null,
            'callback_url' => $options['callback_url'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ];

        $this->logRequest('POST', $this->baseUrl.'/transaction/initialize', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/transaction/initialize', array_filter($payload));

        $data = $response->json();
        $success = ($data['status'] ?? false) === true;

        return [
            'success' => $success,
            'provider_payment_id' => $data['data']['reference'] ?? null,
            'provider_reference' => $data['data']['access_code'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => [
                'authorization_url' => $data['data']['authorization_url'] ?? null,
                ...$data['data'] ?? [],
            ],
        ];
    }

    /**
     * Charge a saved authorization (recurring payment).
     */
    public function chargeAuthorization(string $email, int $amount, string $currency, string $authorizationCode, array $options = []): array
    {
        $payload = [
            'email' => $email,
            'amount' => $amount,
            'currency' => $currency,
            'authorization_code' => $authorizationCode,
            'reference' => $options['reference'] ?? null,
        ];

        $this->logRequest('POST', $this->baseUrl.'/transaction/charge_authorization', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/transaction/charge_authorization', array_filter($payload));

        $data = $response->json();
        $success = ($data['status'] ?? false) === true && ($data['data']['status'] ?? '') === 'success';

        return [
            'success' => $success,
            'provider_payment_id' => $data['data']['reference'] ?? null,
            'provider_reference' => $data['data']['id'] ?? null,
            'status' => $success ? 'completed' : 'failed',
            'metadata' => $data['data'] ?? $data,
        ];
    }
}

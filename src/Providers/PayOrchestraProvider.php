<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PayOrchestraProvider extends BasePaymentProvider
{
    protected string $baseUrl;

    public function __construct(
        protected string $apiKey = '',
        protected string $orgId = '',
        protected string $webhookSecret = '',
        string $baseUrl = 'https://backbone.payorchestra.com',
        protected int $timeout = 30,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    #[\Override]
    public function getName(): string
    {
        return 'payorchestra';
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return $this->baseUrl !== ''
            && $this->apiKey !== ''
            && $this->orgId !== '';
    }

    /**
     * Create a payment intent in the PayOrchestra backbone.
     *
     * Options:
     *   - channel: string (mpesa|card|bank_transfer|...) — channel hint for routing
     *   - phone: string — required for mobile-money channels
     *   - email: string — required for card channel
     *   - reference: string — your bill/invoice reference
     *   - metadata: array — arbitrary data echoed back in webhooks
     *   - callback_url: string — override default webhook URL
     *   - redirect_url: string — for hosted payment page redirect
     */
    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $this->logRequest('POST', '/api/v1/client/payment-intents', $this->scrubSensitiveData([
            'amount' => $amount,
            'currency' => $currency,
            'channel' => $options['channel'] ?? null,
        ]));

        $payload = array_filter([
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $options['reference'] ?? null,
            'metadata' => $options['metadata'] ?? [],
            'callback_url' => $options['callback_url'] ?? $this->defaultCallbackUrl(),
            'channel' => $options['channel'] ?? null,
            'payer_phone' => $options['phone'] ?? null,
            'payer_email' => $options['email'] ?? null,
            'redirect_url' => $options['redirect_url'] ?? null,
        ], fn ($v) => $v !== null);

        $response = $this->client()->post('/api/v1/client/payment-intents', $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => [
                    'error' => $response->json('error.message', 'PayOrchestra request failed'),
                    'code' => $response->json('error.code'),
                    'http_status' => $response->status(),
                ],
            ];
        }

        $data = $response->json('data', []);

        return [
            'success' => true,
            'provider_payment_id' => $data['id'] ?? null,
            'provider_reference' => $data['reference_code'] ?? null,
            'status' => $this->mapStatus($data['status'] ?? 'pending'),
            'metadata' => [
                'channel' => $data['channel'] ?? null,
                'checkout_url' => $data['checkout_url'] ?? null,
                'hosted_payment_url' => $data['hosted_payment_url'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $payload = array_filter([
            'amount' => $amount,
            'reason' => $options['reason'] ?? null,
        ], fn ($v) => $v !== null);

        $response = $this->client()
            ->post("/api/v1/client/payment-intents/{$providerPaymentId}/refund", $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'provider_refund_id' => null,
                'status' => 'failed',
                'metadata' => [
                    'error' => $response->json('error.message', 'PayOrchestra refund failed'),
                ],
            ];
        }

        $data = $response->json('data', []);

        return [
            'success' => true,
            'provider_refund_id' => $data['id'] ?? null,
            'status' => $this->mapStatus($data['status'] ?? 'pending'),
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = $this->client()->get("/api/v1/client/payment-intents/{$providerPaymentId}");

        if ($response->failed()) {
            return 'unknown';
        }

        return $this->mapStatus($response->json('data.status', 'unknown'));
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        $signature = $request->header('X-PayOrchestra-Signature');

        if ($signature === null || $signature === '') {
            return false;
        }

        // Reject if no webhook secret is configured. We deliberately do NOT fall
        // back to the API key — using the API key as the HMAC secret would mean
        // a leaked API key (which travels in every outbound request) could be
        // used to forge inbound webhooks.
        if ($this->webhookSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $event = $request->input('event', 'payment_intent.updated');
        $data = (array) $request->input('data', []);

        return [
            'event' => $event,
            'provider_payment_id' => $data['id'] ?? null,
            'provider_reference' => $data['reference_code'] ?? null,
            'status' => $this->mapStatus($data['status'] ?? ''),
            'amount' => isset($data['amount']) ? (int) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
            'metadata' => array_merge(
                $data['metadata'] ?? [],
                [
                    'provider_reference' => $data['reference_code'] ?? null,
                    'channel' => $data['channel'] ?? null,
                    'paid_at' => $data['paid_at'] ?? null,
                    'failure_reason' => $data['failure_reason'] ?? null,
                ],
            ),
        ];
    }

    /**
     * Create a hosted payment session.
     *
     * Returns a URL to redirect the payer to PayOrchestra's hosted checkout.
     */
    public function createHostedSession(int $amount, string $currency, array $options = []): array
    {
        $payload = array_filter([
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $options['reference'] ?? null,
            'description' => $options['description'] ?? null,
            'payer_name' => $options['payer_name'] ?? null,
            'payer_email' => $options['payer_email'] ?? null,
            'payer_phone' => $options['payer_phone'] ?? null,
            'success_url' => $options['success_url'] ?? null,
            'cancel_url' => $options['cancel_url'] ?? null,
            'metadata' => $options['metadata'] ?? [],
        ], fn ($v) => $v !== null);

        $response = $this->client()->post('/api/v1/client/hosted-payments/sessions', $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'session_url' => null,
                'session_id' => null,
                'expires_at' => null,
                'error' => $response->json('error.message', 'PayOrchestra hosted session failed'),
            ];
        }

        $data = $response->json('data', []);

        return [
            'success' => true,
            'session_url' => $data['payment_url'] ?? null,
            'session_id' => $data['id'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ];
    }

    /**
     * List the active payment connectors installed for this organization.
     *
     * @return array<int, array{slug: string, name: string, channels: array<int, string>, health: string}>
     */
    public function availableChannels(): array
    {
        $response = $this->client()->get('/api/v1/client/connectors/installed');

        if ($response->failed()) {
            return [];
        }

        $connectors = $response->json('data', []);

        return collect($connectors)
            ->filter(fn (array $c) => ($c['status'] ?? null) === 'active')
            ->map(fn (array $c) => [
                'slug' => $c['slug'] ?? '',
                'name' => $c['name'] ?? '',
                'channels' => $c['supported_channels'] ?? [],
                'health' => $c['health_status'] ?? 'unknown',
            ])
            ->values()
            ->all();
    }

    /**
     * Query the PayOrchestra ledger for settlement data.
     *
     * @param  array<string, mixed>  $filters
     */
    public function settlements(array $filters = []): array
    {
        $response = $this->client()->get('/api/v1/client/settlements', $filters);

        return $response->successful() ? (array) $response->json('data', []) : [];
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->withHeaders([
                'X-Organization-Id' => $this->orgId,
                'Accept' => 'application/json',
            ])
            ->timeout($this->timeout);
    }

    protected function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'created', 'pending', 'processing', 'requires_action' => 'pending',
            'completed', 'settled', 'success', 'successful' => 'completed',
            'failed', 'expired', 'cancelled', 'canceled', 'declined' => 'failed',
            'refunded', 'partially_refunded', 'reversed' => 'refunded',
            default => $status === '' ? 'unknown' : $status,
        };
    }

    protected function defaultCallbackUrl(): ?string
    {
        return app('router')->has('billing.webhooks.payorchestra')
            ? route('billing.webhooks.payorchestra')
            : null;
    }
}

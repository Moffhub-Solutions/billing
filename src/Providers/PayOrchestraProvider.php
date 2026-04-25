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
            'channel' => $this->optionNullableString($options, 'channel'),
        ]));

        $payload = array_filter([
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $this->optionNullableString($options, 'reference'),
            'metadata' => $this->optionArray($options, 'metadata'),
            'callback_url' => $this->optionString($options, 'callback_url', $this->defaultCallbackUrl() ?? ''),
            'channel' => $this->optionNullableString($options, 'channel'),
            'payer_phone' => $this->optionNullableString($options, 'phone'),
            'payer_email' => $this->optionNullableString($options, 'email'),
            'redirect_url' => $this->optionNullableString($options, 'redirect_url'),
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->client()->post('/api/v1/client/payment-intents', $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => [
                    'error' => $this->jsonString($response, 'error.message', 'PayOrchestra request failed'),
                    'code' => $this->jsonNullableString($response, 'error.code'),
                    'http_status' => $response->status(),
                ],
            ];
        }

        return [
            'success' => true,
            'provider_payment_id' => $this->jsonNullableString($response, 'data.id'),
            'provider_reference' => $this->jsonNullableString($response, 'data.reference_code'),
            'status' => $this->mapStatus($this->jsonString($response, 'data.status', 'pending')),
            'metadata' => [
                'channel' => $this->jsonNullableString($response, 'data.channel'),
                'checkout_url' => $this->jsonNullableString($response, 'data.checkout_url'),
                'hosted_payment_url' => $this->jsonNullableString($response, 'data.hosted_payment_url'),
            ],
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $payload = array_filter([
            'amount' => $amount,
            'reason' => $this->optionNullableString($options, 'reason'),
        ], fn ($v) => $v !== null);

        $response = $this->client()
            ->post("/api/v1/client/payment-intents/{$providerPaymentId}/refund", $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'provider_refund_id' => null,
                'status' => 'failed',
                'metadata' => [
                    'error' => $this->jsonString($response, 'error.message', 'PayOrchestra refund failed'),
                ],
            ];
        }

        $metadata = $this->jsonArray($response, 'data');
        /** @var array<string, mixed> $metadataTyped */
        $metadataTyped = [];
        foreach ($metadata as $k => $v) {
            if (is_string($k)) {
                $metadataTyped[$k] = $v;
            }
        }

        return [
            'success' => true,
            'provider_refund_id' => $this->jsonNullableString($response, 'data.id'),
            'status' => $this->mapStatus($this->jsonString($response, 'data.status', 'pending')),
            'metadata' => $metadataTyped,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = $this->client()->get("/api/v1/client/payment-intents/{$providerPaymentId}");

        if ($response->failed()) {
            return 'unknown';
        }

        return $this->mapStatus($this->jsonString($response, 'data.status', 'unknown'));
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        $signatureRaw = $request->header('X-PayOrchestra-Signature');
        $signature = is_string($signatureRaw) ? $signatureRaw : '';

        if ($signature === '') {
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
        $eventRaw = $request->input('event', 'payment_intent.updated');
        $event = is_string($eventRaw) ? $eventRaw : 'payment_intent.updated';

        $dataRaw = $request->input('data', []);
        $data = is_array($dataRaw) ? $dataRaw : [];

        $statusValue = $data['status'] ?? '';
        $status = is_string($statusValue) ? $statusValue : '';

        $amountValue = $data['amount'] ?? null;
        $amount = is_numeric($amountValue) ? (int) $amountValue : null;

        $metadataRaw = $data['metadata'] ?? [];
        $metadata = is_array($metadataRaw) ? $metadataRaw : [];

        return [
            'event' => $event,
            'provider_payment_id' => isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            'provider_reference' => isset($data['reference_code']) && is_string($data['reference_code']) ? $data['reference_code'] : null,
            'status' => $this->mapStatus($status),
            'amount' => $amount,
            'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null,
            'metadata' => array_merge(
                $metadata,
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
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, session_url: string|null, session_id: string|null, expires_at: string|null, error?: string}
     */
    public function createHostedSession(int $amount, string $currency, array $options = []): array
    {
        $payload = array_filter([
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $this->optionNullableString($options, 'reference'),
            'description' => $this->optionNullableString($options, 'description'),
            'payer_name' => $this->optionNullableString($options, 'payer_name'),
            'payer_email' => $this->optionNullableString($options, 'payer_email'),
            'payer_phone' => $this->optionNullableString($options, 'payer_phone'),
            'success_url' => $this->optionNullableString($options, 'success_url'),
            'cancel_url' => $this->optionNullableString($options, 'cancel_url'),
            'metadata' => $this->optionArray($options, 'metadata'),
        ], fn ($v) => $v !== null);

        $response = $this->client()->post('/api/v1/client/hosted-payments/sessions', $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'session_url' => null,
                'session_id' => null,
                'expires_at' => null,
                'error' => $this->jsonString($response, 'error.message', 'PayOrchestra hosted session failed'),
            ];
        }

        return [
            'success' => true,
            'session_url' => $this->jsonNullableString($response, 'data.payment_url'),
            'session_id' => $this->jsonNullableString($response, 'data.id'),
            'expires_at' => $this->jsonNullableString($response, 'data.expires_at'),
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

        $connectors = $this->jsonArray($response, 'data');

        $result = [];
        foreach ($connectors as $c) {
            if (! is_array($c)) {
                continue;
            }
            if (($c['status'] ?? null) !== 'active') {
                continue;
            }

            $channelsRaw = $c['supported_channels'] ?? [];
            $channels = [];
            if (is_array($channelsRaw)) {
                foreach ($channelsRaw as $channel) {
                    if (is_string($channel)) {
                        $channels[] = $channel;
                    }
                }
            }

            $result[] = [
                'slug' => isset($c['slug']) && is_string($c['slug']) ? $c['slug'] : '',
                'name' => isset($c['name']) && is_string($c['name']) ? $c['name'] : '',
                'channels' => $channels,
                'health' => isset($c['health_status']) && is_string($c['health_status']) ? $c['health_status'] : 'unknown',
            ];
        }

        return $result;
    }

    /**
     * Query the PayOrchestra ledger for settlement data.
     *
     * @param  array<string, mixed>  $filters
     * @return array<array-key, mixed>
     */
    public function settlements(array $filters = []): array
    {
        $response = $this->client()->get('/api/v1/client/settlements', $filters);

        return $response->successful() ? $this->jsonArray($response, 'data') : [];
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
        $router = app('router');

        return $router->has('billing.webhooks.payorchestra')
            ? route('billing.webhooks.payorchestra')
            : null;
    }
}

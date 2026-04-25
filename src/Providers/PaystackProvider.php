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
        $email = $this->optionNullableString($options, 'email');

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
        $authCode = $this->optionNullableString($options, 'authorization_code');

        if ($authCode !== null) {
            return $this->chargeAuthorization($email, $amount, $currency, $authCode, $options);
        }

        // Otherwise, initialize a new transaction (returns redirect URL)
        return $this->initializeTransaction($email, $amount, $currency, $options);
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        /** @var array<string, mixed> $payload */
        $payload = ['transaction' => $providerPaymentId];

        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        $reason = $this->optionNullableString($options, 'reason');

        if ($reason !== null) {
            $payload['merchant_note'] = $reason;
        }

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/refund', $payload);

        $data = $this->asArray($response->json());
        $statusFlag = $data['status'] ?? false;
        $success = $statusFlag === true;

        $metadataData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        return [
            'success' => $success,
            'provider_refund_id' => $this->jsonNullableString($response, 'data.transaction.id'),
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $metadataData,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl.'/transaction/verify/'.$providerPaymentId);

        return match ($this->jsonString($response, 'data.status', 'unknown')) {
            'success' => 'completed',
            'failed' => 'failed',
            'abandoned' => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }

        $signature = (string) $request->header('x-paystack-signature', '');
        $payload = $request->getContent();
        $expected = hash_hmac('sha512', $payload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $eventRaw = $request->input('event', '');
        $event = is_string($eventRaw) ? $eventRaw : '';

        $dataRaw = $request->input('data', []);
        $data = is_array($dataRaw) ? $dataRaw : [];

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
            'provider_payment_id' => isset($data['reference']) && is_string($data['reference']) ? $data['reference'] : null,
            'status' => $status,
            'amount' => isset($data['amount']) ? $this->asNullableInt($data['amount']) : null, // Paystack amounts are already in kobo/cents
            'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null,
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
        return $this->secretKey !== '';
    }

    #[\Override]
    public function getName(): string
    {
        return 'paystack';
    }

    // ─── Paystack-specific methods ─────────────────────────────────────

    /**
     * Initialize a transaction (returns a redirect URL for the customer).
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function initializeTransaction(string $email, int $amount, string $currency, array $options = []): array
    {
        $payload = [
            'email' => $email,
            'amount' => $amount, // in kobo/cents
            'currency' => $currency,
            'reference' => $this->optionNullableString($options, 'reference'),
            'callback_url' => $this->optionNullableString($options, 'callback_url'),
            'metadata' => $this->optionArray($options, 'metadata') ?: null,
        ];

        $this->logRequest('POST', $this->baseUrl.'/transaction/initialize', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/transaction/initialize', array_filter($payload));

        $data = $this->asArray($response->json());
        $statusFlag = $data['status'] ?? false;
        $success = $statusFlag === true;

        $inner = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];

        return [
            'success' => $success,
            'provider_payment_id' => $this->jsonNullableString($response, 'data.reference'),
            'provider_reference' => $this->jsonNullableString($response, 'data.access_code'),
            'status' => $success ? 'pending' : 'failed',
            'metadata' => [
                'authorization_url' => $this->jsonNullableString($response, 'data.authorization_url'),
                ...$inner,
            ],
        ];
    }

    /**
     * Charge a saved authorization (recurring payment).
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function chargeAuthorization(string $email, int $amount, string $currency, string $authorizationCode, array $options = []): array
    {
        $payload = [
            'email' => $email,
            'amount' => $amount,
            'currency' => $currency,
            'authorization_code' => $authorizationCode,
            'reference' => $this->optionNullableString($options, 'reference'),
        ];

        $this->logRequest('POST', $this->baseUrl.'/transaction/charge_authorization', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/transaction/charge_authorization', array_filter($payload));

        $data = $this->asArray($response->json());
        $statusFlag = $data['status'] ?? false;
        $innerStatus = $this->jsonString($response, 'data.status', '');
        $success = $statusFlag === true && $innerStatus === 'success';

        $metadataData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        return [
            'success' => $success,
            'provider_payment_id' => $this->jsonNullableString($response, 'data.reference'),
            'provider_reference' => $this->jsonNullableString($response, 'data.id'),
            'status' => $success ? 'completed' : 'failed',
            'metadata' => $metadataData,
        ];
    }
}

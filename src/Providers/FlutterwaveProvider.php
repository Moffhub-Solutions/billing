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
        $email = $this->optionNullableString($options, 'email');

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

        $data = $this->asArray($response->json());
        $success = ($data['status'] ?? '') === 'success';

        $metadata = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        return [
            'success' => $success,
            'provider_refund_id' => $this->jsonNullableString($response, 'data.id'),
            'status' => $success ? 'completed' : 'failed',
            'metadata' => $metadata,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl."/transactions/{$providerPaymentId}/verify");

        return match ($this->jsonString($response, 'data.status', 'unknown')) {
            'successful' => 'completed',
            'failed' => 'failed',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }

        $signature = (string) $request->header('verif-hash', '');

        return hash_equals($this->webhookSecret, $signature);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $eventRaw = $request->input('event', '');
        $event = is_string($eventRaw) ? $eventRaw : '';

        $dataRaw = $request->input('data', []);
        $data = is_array($dataRaw) ? $dataRaw : [];

        $idValue = $data['id'] ?? null;
        $providerPaymentId = is_string($idValue) ? $idValue : (is_int($idValue) ? (string) $idValue : '');

        $amountValue = $data['amount'] ?? null;
        $amount = is_numeric($amountValue) ? (int) ($amountValue * 100) : null;

        return [
            'event' => match ($event) {
                'charge.completed' => 'payment.completed',
                'charge.failed' => 'payment.failed',
                default => $event,
            },
            'provider_payment_id' => $providerPaymentId,
            'status' => match ($data['status'] ?? 'unknown') {
                'successful' => 'completed',
                'failed' => 'failed',
                default => 'pending',
            },
            'amount' => $amount,
            'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null,
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
        return $this->secretKey !== '';
    }

    #[\Override]
    public function getName(): string
    {
        return 'flutterwave';
    }

    // ─── Flutterwave-specific ──────────────────────────────────────────

    /**
     * Initialize a standard payment (returns redirect URL).
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function initializePayment(string $email, int $amount, string $currency, array $options = []): array
    {
        $txRef = $this->optionString($options, 'reference', 'FLW-'.uniqid());

        $payload = [
            'tx_ref' => $txRef,
            'amount' => $amount / 100, // Flutterwave expects whole units
            'currency' => $currency,
            'redirect_url' => $this->optionString($options, 'callback_url'),
            'customer' => [
                'email' => $email,
                'name' => $this->optionNullableString($options, 'name'),
                'phonenumber' => $this->optionNullableString($options, 'phone'),
            ],
            'meta' => $this->optionArray($options, 'metadata') ?: null,
            'customizations' => [
                'title' => $this->optionString($options, 'title', 'Payment'),
                'description' => $this->optionString($options, 'description', 'Payment'),
            ],
        ];

        // Restrict to specific payment methods if specified
        $paymentOptions = $this->optionNullableString($options, 'payment_options');
        if ($paymentOptions !== null) {
            $payload['payment_options'] = $paymentOptions; // e.g., "mpesa,card"
        }

        $this->logRequest('POST', $this->baseUrl.'/payments', $payload);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/payments', $payload);

        $data = $this->asArray($response->json());
        $success = ($data['status'] ?? '') === 'success';

        $inner = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];

        return [
            'success' => $success,
            'provider_payment_id' => $txRef,
            'provider_reference' => null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => [
                'payment_link' => $inner['link'] ?? null,
                ...$inner,
            ],
        ];
    }
}

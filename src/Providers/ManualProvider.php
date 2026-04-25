<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ManualProvider extends BasePaymentProvider
{
    public function charge(int $amount, string $currency, array $options = []): array
    {
        // Manual payments are always recorded as pending until confirmed
        return [
            'success' => true,
            'provider_payment_id' => 'manual_'.Str::ulid()->toBase32(),
            'provider_reference' => $this->optionNullableString($options, 'reference'),
            'status' => 'pending',
            'metadata' => [
                'payment_type' => $this->optionString($options, 'payment_type', 'cash'),
                'notes' => $this->optionNullableString($options, 'notes'),
                'recorded_by' => $options['recorded_by'] ?? null,
            ],
        ];
    }

    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        return [
            'success' => true,
            'provider_refund_id' => 'manual_refund_'.Str::ulid()->toBase32(),
            'status' => 'completed',
            'metadata' => [
                'notes' => $this->optionNullableString($options, 'notes'),
                'refunded_by' => $options['refunded_by'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        return 'pending'; // Manual payments must be confirmed externally
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        return true; // No webhooks for manual payments
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        return [
            'event' => 'payment.manual',
            'provider_payment_id' => null,
            'status' => 'pending',
            'amount' => null,
            'currency' => null,
            'metadata' => [],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return true; // Always available
    }

    public function getName(): string
    {
        return 'manual';
    }
}

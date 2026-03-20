<?php

declare(strict_types=1);

namespace Moffhub\Billing\Contracts;

use Illuminate\Http\Request;
use Moffhub\Billing\Models\Payment;

interface PaymentProviderInterface
{
    /**
     * Initiate a charge against the billable.
     *
     * @param  int  $amount  Amount in cents
     * @param  string  $currency  ISO 4217 currency code (e.g., KES, USD)
     * @param  array<string, mixed>  $options  Provider-specific options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function charge(int $amount, string $currency, array $options = []): array;

    /**
     * Process a refund for a previous payment.
     *
     * @param  string  $providerPaymentId  The provider's payment identifier
     * @param  int|null  $amount  Amount to refund in cents (null = full refund)
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_refund_id: string|null, status: string, metadata: array<string, mixed>}
     */
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array;

    /**
     * Query the status of a payment from the provider.
     */
    public function getPaymentStatus(string $providerPaymentId): string;

    /**
     * Verify the authenticity of an incoming webhook request.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Parse a webhook payload into a normalized event array.
     *
     * @return array{event: string, provider_payment_id: string|null, status: string, amount: int|null, currency: string|null, metadata: array<string, mixed>}
     */
    public function parseWebhook(Request $request): array;

    /**
     * Check if the provider is properly configured and reachable.
     */
    public function isConfigured(): bool;

    /**
     * Get the provider's display name.
     */
    public function getName(): string;
}

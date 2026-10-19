<?php

declare(strict_types=1);

namespace Moffhub\Billing\Contracts;

/**
 * Capability for payment providers that can create customer-approved standing
 * orders (recurring debits), for example M-Pesa Ratiba.
 */
interface SupportsStandingOrders
{
    /**
     * Ask the provider to create a standing order on the customer's profile.
     *
     * Recognised options: name, amount (cents), phone, frequency
     * (StandingOrderFrequency|string), start_date, end_date, account_reference,
     * description, callback_url, transaction_type.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_standing_order_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function createStandingOrder(array $options): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, status: string, metadata: array<string, mixed>}
     */
    public function cancelStandingOrder(string $id, array $options = []): array;

    /**
     * @return array{success: bool, status: string, metadata: array<string, mixed>}
     */
    public function getStandingOrderStatus(string $id): array;

    /**
     * Normalise a provider callback into a flat structure.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{provider_reference: string|null, request_reference: string|null, account_reference: string|null, standing_order_id: string|null, transaction_id: string|null, amount: int|null, success: bool, result_code: string|null, description: string|null, status: string|null, raw: array<array-key, mixed>}
     */
    public function parseStandingOrderCallback(array $payload): array;
}

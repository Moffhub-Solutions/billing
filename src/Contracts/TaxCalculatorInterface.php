<?php

declare(strict_types=1);

namespace Moffhub\Billing\Contracts;

interface TaxCalculatorInterface
{
    /**
     * Calculate tax for a given amount.
     *
     * @param  int  $amount  Amount in cents (tax-exclusive)
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<string, mixed>  $context  Additional context (e.g., item category, customer location)
     * @return array{tax_amount: int, tax_rate: float, tax_label: string, breakdown: array<string, int>}
     */
    public function calculate(int $amount, string $currency, array $context = []): array;

    /**
     * Check if an item is tax-exempt.
     *
     * @param  array<string, mixed>  $context
     */
    public function isExempt(array $context = []): bool;
}

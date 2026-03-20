<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Moffhub\Billing\Contracts\TaxCalculatorInterface;

class KenyanTaxCalculator implements TaxCalculatorInterface
{
    private const float VAT_RATE = 16.0;

    private const float WHT_RATE = 5.0;

    private const array EXEMPT_CATEGORIES = ['exempt', 'zero_rated'];

    /**
     * {@inheritDoc}
     */
    public function calculate(int $amount, string $currency, array $context = []): array
    {
        $category = $context['tax_category'] ?? 'standard';
        $applyWht = $context['apply_wht'] ?? false;

        $vatRate = $this->resolveVatRate($category);
        $vatAmount = (int) round($amount * ($vatRate / 100));

        $breakdown = ['vat' => $vatAmount];
        $totalTax = $vatAmount;

        if ($applyWht) {
            $whtAmount = (int) round($amount * (self::WHT_RATE / 100));
            $breakdown['wht'] = $whtAmount;
            $totalTax += $whtAmount;
        }

        return [
            'tax_amount' => $totalTax,
            'tax_rate' => $vatRate,
            'tax_label' => 'VAT',
            'breakdown' => $breakdown,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function isExempt(array $context = []): bool
    {
        $category = $context['tax_category'] ?? 'standard';

        return in_array($category, self::EXEMPT_CATEGORIES, true);
    }

    private function resolveVatRate(string $category): float
    {
        return match ($category) {
            'zero_rated', 'exempt' => 0.0,
            default => self::VAT_RATE,
        };
    }
}

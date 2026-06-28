<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Moffhub\Billing\Exceptions\TransactionLimitException;

/**
 * Splits a payable amount into tranches that respect a provider's
 * per-transaction limit, so a charge larger than the cap can be collected as
 * several smaller payments (e.g. 500,000 KES via two 250,000 M-Pesa pushes).
 *
 * Pure and side-effect free: it computes the plan only. Persisting the tranche
 * payments and initiating collection is the caller's job.
 */
class PaymentSplitter
{
    /**
     * Split an amount (in cents) into tranches no larger than the provider cap.
     *
     * Uses a fill-max strategy: every tranche is the maximum amount except the
     * final remainder. When the provider has no per-transaction cap, the amount
     * is returned as a single tranche.
     *
     * @param  array{max_amount?: int|null, max_per_day?: int|null}  $limits
     * @param  int  $usedToday  Transactions already consumed today (counts toward max_per_day)
     * @return array<int, int> Tranche amounts in cents; sums to $amount
     *
     * @throws TransactionLimitException when the split cannot fit the daily allowance
     */
    public function split(int $amount, array $limits, string $provider = '', int $usedToday = 0): array
    {
        $maxAmount = $limits['max_amount'] ?? null;
        $maxPerDay = $limits['max_per_day'] ?? null;

        $tranches = $this->buildTranches($amount, is_int($maxAmount) && $maxAmount > 0 ? $maxAmount : null);

        if (is_int($maxPerDay) && $maxPerDay > 0) {
            $remaining = max(0, $maxPerDay - max(0, $usedToday));

            if (count($tranches) > $remaining) {
                throw TransactionLimitException::dailyCapExceeded(
                    $provider !== '' ? $provider : 'provider',
                    $amount,
                    count($tranches),
                    $remaining,
                );
            }
        }

        return $tranches;
    }

    /**
     * Whether an amount needs to be split for the given limits.
     *
     * @param  array{max_amount?: int|null, max_per_day?: int|null}  $limits
     */
    public function requiresSplit(int $amount, array $limits): bool
    {
        $maxAmount = $limits['max_amount'] ?? null;

        return is_int($maxAmount) && $maxAmount > 0 && $amount > $maxAmount;
    }

    /**
     * @return array<int, int>
     */
    private function buildTranches(int $amount, ?int $maxAmount): array
    {
        if ($amount <= 0) {
            return [$amount];
        }

        if ($maxAmount === null || $amount <= $maxAmount) {
            return [$amount];
        }

        $tranches = [];
        $remaining = $amount;

        while ($remaining > $maxAmount) {
            $tranches[] = $maxAmount;
            $remaining -= $maxAmount;
        }

        $tranches[] = $remaining;

        return $tranches;
    }
}

<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;

class ProrationCalculator
{
    /**
     * Calculate proration amounts when switching plans.
     *
     * @return array{credit: int, charge: int, net: int}
     */
    public function calculate(Subscription $subscription, Plan $newPlan): array
    {
        $currentPlan = $subscription->plan;

        $periodStart = $subscription->current_period_start;
        $periodEnd = $subscription->current_period_end;

        if ($periodStart === null || $periodEnd === null) {
            return [
                'credit' => 0,
                'charge' => $newPlan->base_price,
                'net' => $newPlan->base_price,
            ];
        }

        $totalDays = (int) $periodStart->diffInDays($periodEnd);

        if ($totalDays <= 0) {
            return [
                'credit' => 0,
                'charge' => $newPlan->base_price,
                'net' => $newPlan->base_price,
            ];
        }

        $usedDays = (int) $periodStart->diffInDays(now());
        $remainingDays = max(0, $totalDays - $usedDays);

        // Credit: unused portion of old plan
        $dailyRateOld = $currentPlan->base_price / $totalDays;
        $credit = (int) round($dailyRateOld * $remainingDays);

        // Charge: remaining portion of new plan at new plan's cycle rate
        $newTotalDays = $newPlan->billing_cycle->days();
        $dailyRateNew = $newPlan->base_price / $newTotalDays;
        $charge = (int) round($dailyRateNew * $remainingDays);

        $net = $charge - $credit;

        return [
            'credit' => $credit,
            'charge' => $charge,
            'net' => $net,
        ];
    }
}

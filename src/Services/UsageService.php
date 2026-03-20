<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Moffhub\Billing\Events\UsageLimitApproaching;
use Moffhub\Billing\Models\UsageEvent;
use Moffhub\Billing\Models\UsageRecord;

class UsageService
{
    /**
     * Record a usage event for a billable.
     *
     * @param  string|null  $transactionId  Unique ID for deduplication
     */
    public function record(
        Model $billable,
        string $featureSlug,
        int $quantity = 1,
        ?string $transactionId = null,
        array $properties = [],
    ): UsageEvent {
        // Deduplication check
        if ($transactionId !== null) {
            $existing = UsageEvent::where('transaction_id', $transactionId)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        // Create the raw event
        $event = new UsageEvent([
            'ulid' => Str::ulid()->toBase32(),
            'feature_slug' => $featureSlug,
            'quantity' => $quantity,
            'transaction_id' => $transactionId,
            'properties' => $properties ?: null,
            'recorded_at' => now(),
        ]);

        $billable->morphMany(UsageEvent::class, 'billable')->save($event);

        // Update the aggregate usage record
        $record = $this->getOrCreateCurrentRecord($billable, $featureSlug);
        $record->increment('usage_count', $quantity);

        // Check alert thresholds
        $this->checkThresholds($billable, $record);

        return $event;
    }

    /**
     * Get the current period usage record, creating one if needed.
     */
    public function getOrCreateCurrentRecord(Model $billable, string $featureSlug): UsageRecord
    {
        $subscription = method_exists($billable, 'subscription')
            ? $billable->subscription()
            : null;

        $periodStart = $subscription?->current_period_start ?? now()->startOfMonth();
        $periodEnd = $subscription?->current_period_end ?? now()->endOfMonth();

        // Resolve the usage limit from the plan
        $limit = null;

        if ($subscription !== null) {
            $limit = $subscription->plan->getLimit($featureSlug);
        }

        return UsageRecord::firstOrCreate(
            [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'feature_slug' => $featureSlug,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'ulid' => Str::ulid()->toBase32(),
                'usage_count' => 0,
                'usage_limit' => $limit,
                'overage_count' => 0,
            ],
        );
    }

    /**
     * Get current usage count for a feature.
     */
    public function getUsage(Model $billable, string $featureSlug): int
    {
        return $billable->morphMany(UsageRecord::class, 'billable')
            ->where('feature_slug', $featureSlug)
            ->where('period_start', '<=', now())
            ->where('period_end', '>=', now())
            ->value('usage_count') ?? 0;
    }

    /**
     * Check if usage is within the limit.
     */
    public function isWithinLimit(Model $billable, string $featureSlug): bool
    {
        $record = $billable->morphMany(UsageRecord::class, 'billable')
            ->where('feature_slug', $featureSlug)
            ->where('period_start', '<=', now())
            ->where('period_end', '>=', now())
            ->first();

        if ($record === null) {
            return true;
        }

        return $record->isWithinLimit();
    }

    /**
     * Reset usage for a new billing period.
     */
    public function resetUsage(Model $billable, string $featureSlug): void
    {
        $subscription = method_exists($billable, 'subscription')
            ? $billable->subscription()
            : null;

        $limit = null;

        if ($subscription !== null) {
            $limit = $subscription->plan->getLimit($featureSlug);
        }

        $billable->morphMany(UsageRecord::class, 'billable')->create([
            'ulid' => Str::ulid()->toBase32(),
            'feature_slug' => $featureSlug,
            'period_start' => now(),
            'period_end' => now()->addDays($subscription?->plan->billing_cycle->days() ?? 30),
            'usage_count' => 0,
            'usage_limit' => $limit,
            'overage_count' => 0,
        ]);
    }

    /**
     * Check if usage has hit configured alert thresholds.
     */
    protected function checkThresholds(Model $billable, UsageRecord $record): void
    {
        if ($record->usage_limit === null || $record->usage_limit === 0) {
            return;
        }

        $percentage = ($record->usage_count / $record->usage_limit) * 100;
        $thresholds = config('billing.usage.alert_thresholds', [80, 90, 100]);

        foreach ($thresholds as $threshold) {
            $previousCount = $record->usage_count - 1;
            $previousPercentage = ($previousCount / $record->usage_limit) * 100;

            // Fire event only when crossing the threshold
            if ($percentage >= $threshold && $previousPercentage < $threshold) {
                UsageLimitApproaching::dispatch(
                    $billable,
                    $record->feature_slug,
                    $record->usage_count,
                    $record->usage_limit,
                    $percentage / 100,
                );
            }
        }
    }
}

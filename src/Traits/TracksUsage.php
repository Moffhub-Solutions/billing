<?php

declare(strict_types=1);

namespace Moffhub\Billing\Traits;

use Moffhub\Billing\Services\UsageService;

/**
 * Add this trait to any Eloquent model to automatically record usage events
 * when the model is created, updated, or deleted.
 *
 * SIMPLE — same feature for all events:
 *
 *     class OcrScan extends Model
 *     {
 *         use TracksUsage;
 *
 *         protected static string $usageFeatureSlug = 'ocr_scanning';
 *     }
 *
 * PER-OPERATION — different features/quantities per event:
 *
 *     class Document extends Model
 *     {
 *         use TracksUsage;
 *
 *         protected static array $usageMap = [
 *             'created' => ['feature' => 'document_uploads', 'quantity' => 1],
 *             'updated' => ['feature' => 'document_edits',   'quantity' => 1],
 *             'deleted' => ['feature' => 'document_deletes', 'quantity' => 1],
 *         ];
 *     }
 *
 * DYNAMIC — resolve feature/quantity at runtime based on model state:
 *
 *     class Entry extends Model
 *     {
 *         use TracksUsage;
 *
 *         protected static array $usageTrackOn = ['created'];
 *
 *         public function resolveUsage(string $event): ?array
 *         {
 *             return match ($this->type) {
 *                 'visitor' => ['feature' => 'visitor_entries',  'quantity' => 1],
 *                 'vehicle' => ['feature' => 'vehicle_entries',  'quantity' => 1],
 *                 default   => ['feature' => 'general_entries',  'quantity' => 1],
 *             };
 *         }
 *     }
 */
trait TracksUsage
{
    public static function bootTracksUsage(): void
    {
        $events = static::resolveTrackedEvents();

        foreach ($events as $event) {
            static::$event(function ($model) use ($event): void {
                $model->recordUsageEvent($event);
            });
        }
    }

    /**
     * Record a usage event for this model's billable.
     */
    protected function recordUsageEvent(string $event): void
    {
        $billable = $this->resolveUsageBillable();

        if ($billable === null) {
            return;
        }

        $usage = $this->resolveUsageForEvent($event);

        if ($usage === null) {
            return;
        }

        $featureSlug = $usage['feature'];
        $quantity = $usage['quantity'] ?? 1;

        // Model class + key + event = deduplication key
        $transactionId = class_basename(static::class).':'.$this->getKey().':'.$event;

        app(UsageService::class)->record(
            billable: $billable,
            featureSlug: $featureSlug,
            quantity: $quantity,
            transactionId: $transactionId,
            properties: $this->usageProperties($event),
        );
    }

    /**
     * Resolve which feature and quantity to bill for a given event.
     * Returns null to skip tracking for this event.
     */
    protected function resolveUsageForEvent(string $event): ?array
    {
        // Priority 1: model defines resolveUsage() for dynamic resolution
        if (method_exists($this, 'resolveUsage')) {
            return $this->resolveUsage($event);
        }

        // Priority 2: per-operation map ($usageMap)
        if (isset(static::$usageMap) && is_array(static::$usageMap)) {
            return static::$usageMap[$event] ?? null;
        }

        // Priority 3: simple single feature slug ($usageFeatureSlug)
        $featureSlug = static::$usageFeatureSlug ?? null;

        if ($featureSlug === null) {
            return null;
        }

        return [
            'feature' => $featureSlug,
            'quantity' => static::$usageQuantity ?? 1,
        ];
    }

    /**
     * Determine which model events to track.
     */
    protected static function resolveTrackedEvents(): array
    {
        // If usageMap is defined, track all events in the map
        if (isset(static::$usageMap) && is_array(static::$usageMap)) {
            return array_keys(static::$usageMap);
        }

        // Otherwise use explicit list or default to ['created']
        return static::$usageTrackOn ?? ['created'];
    }

    /**
     * Resolve the billable entity from this model.
     * Override usageBillable() in your model for custom resolution.
     */
    protected function resolveUsageBillable(): mixed
    {
        if (method_exists($this, 'usageBillable')) {
            return $this->usageBillable();
        }

        foreach (['company', 'team', 'organization', 'user'] as $relation) {
            if (method_exists($this, $relation)) {
                $billable = $this->{$relation};

                if ($billable !== null && method_exists($billable, 'usageRecords')) {
                    return $billable;
                }
            }
        }

        return null;
    }

    /**
     * Extra properties to attach to the usage event.
     * Override in your model to add context.
     */
    protected function usageProperties(string $event): array
    {
        return [
            'model_type' => static::class,
            'model_id' => $this->getKey(),
            'event' => $event,
        ];
    }
}

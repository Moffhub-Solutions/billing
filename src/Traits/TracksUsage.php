<?php

declare(strict_types=1);

namespace Moffhub\Billing\Traits;

use Illuminate\Database\Eloquent\Model;
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
 * DYNAMIC — override resolveUsage() to resolve feature/quantity at runtime:
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
 *
 * Override usageBillable() in your model to point at the billable entity that
 * holds the subscription (defaults to walking common relations: company, team,
 * organization, user).
 */
trait TracksUsage
{
    public static function bootTracksUsage(): void
    {
        $events = static::resolveTrackedEvents();

        foreach ($events as $event) {
            static::$event(function (Model $model) use ($event): void {
                if (method_exists($model, 'recordUsageEvent')) {
                    $model->recordUsageEvent($event);
                }
            });
        }
    }

    /**
     * Override in your model to point at the billable entity that holds the
     * subscription. Default returns null — meaning the trait will fall back to
     * walking common relations (company, team, organization, user).
     */
    public function usageBillable(): ?Model
    {
        return null;
    }

    /**
     * Override in your model for runtime-dynamic feature/quantity resolution.
     *
     * Return shape: ['feature' => string, 'quantity' => int (optional, defaults to 1)].
     * Return null to skip tracking for this event.
     *
     * @return array<string, mixed>|null
     */
    public function resolveUsage(string $event): ?array
    {
        return null;
    }

    /**
     * Record a usage event for this model's billable.
     */
    protected function recordUsageEvent(string $event): void
    {
        $billable = $this->resolveUsageBillable();

        if (! $billable instanceof Model) {
            return;
        }

        $usage = $this->resolveUsageForEvent($event);

        if ($usage === null || $usage['feature'] === '') {
            return;
        }

        $quantity = $usage['quantity'] ?? 1;

        $key = $this instanceof Model ? $this->getKey() : null;
        $keyString = (is_int($key) || is_string($key)) ? (string) $key : '';
        $transactionId = class_basename(static::class).':'.$keyString.':'.$event;

        app(UsageService::class)->record(
            billable: $billable,
            featureSlug: $usage['feature'],
            quantity: $quantity,
            transactionId: $transactionId,
            properties: $this->usageProperties($event),
        );
    }

    /**
     * Resolve which feature and quantity to bill for a given event.
     * Returns null to skip tracking for this event.
     *
     * @return array{feature: string, quantity?: int}|null
     */
    protected function resolveUsageForEvent(string $event): ?array
    {
        // Priority 1: model overrides resolveUsage() for dynamic resolution.
        $dynamic = $this->resolveUsage($event);

        if ($dynamic !== null) {
            $feature = $dynamic['feature'] ?? null;

            if (! is_string($feature) || $feature === '') {
                return null;
            }

            $quantity = $dynamic['quantity'] ?? 1;

            return [
                'feature' => $feature,
                'quantity' => is_numeric($quantity) ? (int) $quantity : 1,
            ];
        }

        // Priority 2: per-operation map ($usageMap)
        $map = static::tracksUsageStaticArray('usageMap');

        if ($map !== null) {
            $entry = $map[$event] ?? null;

            if (! is_array($entry)) {
                return null;
            }

            $feature = $entry['feature'] ?? null;
            if (! is_string($feature) || $feature === '') {
                return null;
            }

            $quantity = $entry['quantity'] ?? 1;

            return [
                'feature' => $feature,
                'quantity' => is_numeric($quantity) ? (int) $quantity : 1,
            ];
        }

        // Priority 3: simple single feature slug ($usageFeatureSlug)
        $featureSlug = static::tracksUsageStaticString('usageFeatureSlug');

        if ($featureSlug === null) {
            return null;
        }

        return [
            'feature' => $featureSlug,
            'quantity' => static::tracksUsageStaticInt('usageQuantity', 1),
        ];
    }

    /**
     * Determine which model events to track.
     *
     * @return array<int, string>
     */
    protected static function resolveTrackedEvents(): array
    {
        // If usageMap is defined, track all events in the map
        $map = static::tracksUsageStaticArray('usageMap');

        if ($map !== null) {
            $keys = [];
            foreach (array_keys($map) as $key) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
            }

            return $keys;
        }

        // Otherwise use explicit list or default to ['created']
        $events = static::tracksUsageStaticArray('usageTrackOn');

        if ($events !== null) {
            $strings = [];
            foreach ($events as $value) {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            }

            return $strings;
        }

        return ['created'];
    }

    /**
     * Resolve the billable entity from this model.
     */
    protected function resolveUsageBillable(): ?Model
    {
        $explicit = $this->usageBillable();

        if ($explicit instanceof Model) {
            return $explicit;
        }

        if (! $this instanceof Model) {
            return null;
        }

        foreach (['company', 'team', 'organization', 'user'] as $relation) {
            if (! method_exists($this, $relation)) {
                continue;
            }

            $billable = $this->getAttribute($relation);

            if ($billable instanceof Model && method_exists($billable, 'usageRecords')) {
                return $billable;
            }
        }

        return null;
    }

    /**
     * Extra properties to attach to the usage event.
     * Override in your model to add context.
     *
     * @return array<string, mixed>
     */
    protected function usageProperties(string $event): array
    {
        $key = $this instanceof Model ? $this->getKey() : null;

        return [
            'model_type' => static::class,
            'model_id' => $key,
            'event' => $event,
        ];
    }

    /**
     * Read a static array property defined on the consuming class.
     * Returns null if the property isn't declared.
     *
     * @return array<array-key, mixed>|null
     */
    public static function tracksUsageStaticArray(string $name): ?array
    {
        if (! property_exists(static::class, $name)) {
            return null;
        }

        $value = static::${$name} ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * Read a static string property defined on the consuming class.
     * Returns null if the property isn't declared or isn't a string.
     */
    public static function tracksUsageStaticString(string $name): ?string
    {
        if (! property_exists(static::class, $name)) {
            return null;
        }

        $value = static::${$name} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read a static int property defined on the consuming class, falling back to $default.
     */
    public static function tracksUsageStaticInt(string $name, int $default = 0): int
    {
        if (! property_exists(static::class, $name)) {
            return $default;
        }

        $value = static::${$name} ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }
}

<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Moffhub\Billing\Models\Setting;

/**
 * Runtime resolver for the subset of billing config that operators and
 * individual billables may change without editing config or redeploying.
 *
 * A read resolves in this order:
 *   1. a per-billable override (when a billable is supplied),
 *   2. a global override,
 *   3. the value baked into config/billing.php (the shipped default).
 *
 * Only keys on {@see self::OVERRIDABLE} may be stored. Everything else
 * (credentials, table names, route prefixes, security toggles) stays
 * config-only so secrets never reach the database and boot-time wiring,
 * which runs before any request scope exists, stays deterministic.
 */
class BillingSettings
{
    /**
     * Keys (relative to the `billing.` config root) that may be overridden at
     * runtime. A `*` matches exactly one dotted segment.
     *
     * @var list<string>
     */
    private const OVERRIDABLE = [
        'currency',
        'default_provider',
        'enabled_providers',
        'offer_cash',
        'tax.enabled',
        'tax.default_rate',
        'tax.label',
        'invoices.prefix',
        'invoices.due_days',
        'invoices.company_name',
        'invoices.company_address',
        'invoices.company_phone',
        'invoices.company_email',
        'invoices.tax_pin',
        'subscriptions.grace_period_days',
        'subscriptions.dunning_schedule',
        'subscriptions.allow_pause',
        'subscriptions.prorate',
        'usage.allow_overage',
        'usage.alert_thresholds',
        'split_payments.enabled',
        'split_payments.auto_advance',
        'features.driver',
        'features.cache_ttl',
        'providers.*.limits.max_amount',
        'providers.*.limits.max_per_day',
    ];

    /**
     * Per-scope override maps resolved during this request, to avoid hitting
     * the cache (or DB) more than once per scope.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Resolve an overridable billing key: per-billable override, then global
     * override, then the config default. Non-overridable keys read straight
     * from config (the override layer never shadows them).
     */
    public function get(string $key, mixed $default = null, ?Model $billable = null): mixed
    {
        if (! $this->isOverridable($key)) {
            return $this->config->get('billing.'.$key, $default);
        }

        if ($billable instanceof Model) {
            $scoped = $this->scopedMap($billable);

            if (array_key_exists($key, $scoped)) {
                return $scoped[$key];
            }
        }

        $global = $this->globalMap();

        if (array_key_exists($key, $global)) {
            return $global[$key];
        }

        return $this->config->get('billing.'.$key, $default);
    }

    /**
     * Store (or update) an override. Pass a billable to scope it to one
     * tenant; omit it to set the global override.
     *
     * @throws InvalidArgumentException when the key is not runtime-overridable
     */
    public function set(string $key, mixed $value, ?Model $billable = null): void
    {
        $this->assertOverridable($key);

        [$type, $id] = $this->scopeOf($billable);

        Setting::query()->updateOrCreate(
            ['key' => $key, 'billable_type' => $type, 'billable_id' => $id],
            ['value' => $value],
        );

        $this->flush($type, $id);
    }

    /**
     * Remove an override so the key falls back to the next layer down.
     */
    public function forget(string $key, ?Model $billable = null): void
    {
        $this->assertOverridable($key);

        [$type, $id] = $this->scopeOf($billable);

        Setting::query()
            ->where('key', $key)
            ->where('billable_type', $type)
            ->where('billable_id', $id)
            ->delete();

        $this->flush($type, $id);
    }

    /**
     * The raw stored overrides for a scope (global when no billable is given).
     * Useful for an admin UI that shows what has been customised.
     *
     * @return array<string, mixed>
     */
    public function overrides(?Model $billable = null): array
    {
        $map = $billable instanceof Model
            ? $this->scopedMap($billable)
            : $this->globalMap();

        // Re-filter to overridable keys: a row written out-of-band (direct SQL,
        // a seeder, a future relaxed allowlist) with a credential-shaped key is
        // never surfaced here, even though set() already blocks writing one.
        return array_filter($map, fn (string $key): bool => $this->isOverridable($key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The list of keys (relative to `billing.`) that may be overridden.
     *
     * @return list<string>
     */
    public function overridableKeys(): array
    {
        return self::OVERRIDABLE;
    }

    /**
     * Whether a key may be overridden at runtime.
     */
    public function isOverridable(string $key): bool
    {
        foreach (self::OVERRIDABLE as $pattern) {
            if ($this->matches($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    private function assertOverridable(string $key): void
    {
        if (! $this->isOverridable($key)) {
            throw new InvalidArgumentException(
                "Billing setting '{$key}' is not runtime-overridable. ".
                'Set it in config/billing.php (or via env) instead.'
            );
        }
    }

    /**
     * Match a key against a pattern where `*` is a single-segment wildcard.
     */
    private function matches(string $pattern, string $key): bool
    {
        if ($pattern === $key) {
            return true;
        }

        $patternParts = explode('.', $pattern);
        $keyParts = explode('.', $key);

        if (count($patternParts) !== count($keyParts)) {
            return false;
        }

        foreach ($patternParts as $i => $part) {
            if ($part !== '*' && $part !== $keyParts[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function globalMap(): array
    {
        return $this->map($this->cacheKey('', ''), '', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function scopedMap(Model $billable): array
    {
        [$type, $id] = $this->scopeOf($billable);

        return $this->map($this->cacheKey($type, $id), $type, $id);
    }

    /**
     * Load (and memoise/cache) the override map for one scope.
     *
     * @return array<string, mixed>
     */
    private function map(string $cacheKey, string $type, string $id): array
    {
        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }

        $ttl = $this->cacheTtl();
        $loader = fn (): array => $this->loadMap($type, $id);

        $map = $ttl > 0
            ? $this->store()->remember($cacheKey, $ttl, $loader)
            : $loader();

        return $this->memo[$cacheKey] = $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMap(string $type, string $id): array
    {
        try {
            /** @var array<string, mixed> $map */
            $map = Setting::query()
                ->where('billable_type', $type)
                ->where('billable_id', $id)
                ->get()
                ->pluck('value', 'key')
                ->all();

            return $map;
        } catch (QueryException) {
            // The billing_settings table may not exist yet (package installed,
            // migration not run). Degrade to "no overrides" so every read still
            // resolves to its config default instead of throwing.
            return [];
        }
    }

    private function flush(string $type, string $id): void
    {
        $cacheKey = $this->cacheKey($type, $id);

        unset($this->memo[$cacheKey]);

        if ($this->cacheTtl() > 0) {
            $this->store()->forget($cacheKey);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function scopeOf(?Model $billable): array
    {
        if (! $billable instanceof Model) {
            return ['', ''];
        }

        $key = $billable->getKey();
        $id = (is_string($key) || is_int($key)) ? (string) $key : '';

        return [$billable->getMorphClass(), $id];
    }

    private function cacheKey(string $type, string $id): string
    {
        $prefixValue = $this->config->get('billing.settings.cache_prefix', 'billing_settings');
        $prefix = is_string($prefixValue) ? $prefixValue : 'billing_settings';

        $scope = $type === '' && $id === ''
            ? 'global'
            : 'b:'.str_replace('\\', '.', $type).':'.$id;

        return $prefix.':'.$scope;
    }

    private function cacheTtl(): int
    {
        $value = $this->config->get('billing.settings.cache_ttl', 300);

        return is_numeric($value) ? (int) $value : 300;
    }

    private function store(): CacheRepository
    {
        $storeValue = $this->config->get('billing.settings.cache_store');
        $store = is_string($storeValue) && $storeValue !== '' ? $storeValue : null;

        return $this->cache->store($store);
    }
}

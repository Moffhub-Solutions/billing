<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use InvalidArgumentException;
use Moffhub\Billing\Models\Setting;
use Moffhub\Billing\Services\BillingSettings;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class BillingSettingsTest extends BaseTestCase
{
    private function settings(): BillingSettings
    {
        return $this->app()->make(BillingSettings::class);
    }

    // ── Resolution order ──────────────────────────────────────────

    public function test_falls_back_to_config_when_no_override_exists(): void
    {
        $this->setConfig('billing.currency', 'KES');

        $this->assertSame('KES', $this->settings()->get('currency'));
    }

    public function test_returns_the_supplied_default_when_neither_override_nor_config_present(): void
    {
        // An overridable key with no config entry (no row, no config default):
        // get() mirrors config() semantics and returns the supplied default.
        $key = 'providers.zzz.limits.max_amount';
        $this->assertNull($this->config()->get('billing.'.$key));

        $this->assertSame('FALLBACK', $this->settings()->get($key, 'FALLBACK'));
    }

    public function test_global_override_takes_precedence_over_config(): void
    {
        $this->setConfig('billing.currency', 'KES');

        $this->settings()->set('currency', 'USD');

        $this->assertSame('USD', $this->settings()->get('currency'));
    }

    public function test_per_billable_override_takes_precedence_over_global_and_config(): void
    {
        $this->setConfig('billing.currency', 'KES');
        $company = Company::create(['name' => 'Acme']);

        $this->settings()->set('currency', 'USD');                 // global
        $this->settings()->set('currency', 'NGN', $company);       // tenant

        $this->assertSame('NGN', $this->settings()->get('currency', 'KES', $company));
        // A different (override-less) billable still sees the global value.
        $other = Company::create(['name' => 'Globex']);
        $this->assertSame('USD', $this->settings()->get('currency', 'KES', $other));
        // The global read is unaffected by the tenant override.
        $this->assertSame('USD', $this->settings()->get('currency'));
    }

    public function test_billable_falls_back_to_global_then_config_when_no_tenant_override(): void
    {
        $this->setConfig('billing.subscriptions.grace_period_days', 7);
        $company = Company::create(['name' => 'Acme']);

        // No override anywhere -> config.
        $this->assertSame(7, $this->settings()->get('subscriptions.grace_period_days', 7, $company));

        // Global only -> tenant read sees the global value.
        $this->settings()->set('subscriptions.grace_period_days', 14);
        $this->assertSame(14, $this->settings()->get('subscriptions.grace_period_days', 7, $company));
    }

    // ── Types round-trip ──────────────────────────────────────────

    public function test_boolean_and_array_values_round_trip_with_their_type(): void
    {
        $this->settings()->set('offer_cash', false);
        $this->settings()->set('enabled_providers', ['mpesa', 'airtel']);

        $this->assertFalse($this->settings()->get('offer_cash', true));
        $this->assertSame(['mpesa', 'airtel'], $this->settings()->get('enabled_providers', []));
    }

    // ── forget ────────────────────────────────────────────────────

    public function test_forget_removes_the_override_and_falls_back(): void
    {
        $this->setConfig('billing.currency', 'KES');
        $this->settings()->set('currency', 'USD');
        $this->assertSame('USD', $this->settings()->get('currency'));

        $this->settings()->forget('currency');

        $this->assertSame('KES', $this->settings()->get('currency'));
    }

    // ── Allowlist ─────────────────────────────────────────────────

    public function test_provider_limit_keys_are_overridable_via_wildcard(): void
    {
        $this->assertTrue($this->settings()->isOverridable('providers.mpesa.limits.max_amount'));
        $this->assertTrue($this->settings()->isOverridable('providers.airtel.limits.max_per_day'));
    }

    public function test_deploy_time_keys_are_not_overridable(): void
    {
        $this->assertFalse($this->settings()->isOverridable('tables.payments'));
        $this->assertFalse($this->settings()->isOverridable('providers.mpesa.consumer_secret'));
        $this->assertFalse($this->settings()->isOverridable('routes.prefix'));
    }

    public function test_non_overridable_key_reads_straight_from_config(): void
    {
        $this->setConfig('billing.tables.payments', 'billing_payments');

        $this->assertSame('billing_payments', $this->settings()->get('tables.payments'));
    }

    public function test_setting_a_non_overridable_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->set('providers.mpesa.consumer_secret', 'leaked');
    }

    // ── Caching ───────────────────────────────────────────────────

    public function test_set_flushes_the_cache_so_reads_reflect_the_new_value(): void
    {
        $this->setConfig('billing.settings.cache_ttl', 300); // caching on
        $this->setConfig('billing.currency', 'KES');

        // Prime the cache with the config-backed value.
        $this->assertSame('KES', $this->settings()->get('currency'));

        $this->settings()->set('currency', 'USD');

        $this->assertSame('USD', $this->settings()->get('currency'));
    }

    public function test_overrides_returns_only_stored_values_for_the_scope(): void
    {
        $company = Company::create(['name' => 'Acme']);
        $this->settings()->set('currency', 'USD');
        $this->settings()->set('offer_cash', false, $company);

        $this->assertSame(['currency' => 'USD'], $this->settings()->overrides());
        $this->assertSame(['offer_cash' => false], $this->settings()->overrides($company));
    }

    public function test_overrides_never_surfaces_a_non_overridable_out_of_band_row(): void
    {
        $this->settings()->set('currency', 'USD');

        // Simulate a credential-shaped row inserted out-of-band (bypassing set()).
        Setting::query()->create([
            'key' => 'providers.mpesa.consumer_secret',
            'billable_type' => '',
            'billable_id' => '',
            'value' => 'leaked-secret',
        ]);

        $overrides = $this->settings()->overrides();

        $this->assertArrayHasKey('currency', $overrides);
        $this->assertArrayNotHasKey('providers.mpesa.consumer_secret', $overrides);
    }

    public function test_set_is_idempotent_on_the_same_scope(): void
    {
        $company = Company::create(['name' => 'Acme']);

        $this->settings()->set('currency', 'USD', $company);
        $this->settings()->set('currency', 'NGN', $company);

        $table = (new Setting)->getTable();
        $this->assertSame(1, Setting::query()->from($table)->where('key', 'currency')->count());
        $this->assertSame('NGN', $this->settings()->get('currency', 'KES', $company));
    }
}

<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Facades\BillingSettings as BillingSettingsFacade;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Setting;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\BillingSettings;
use Moffhub\Billing\Services\InvoiceService;
use Moffhub\Billing\Services\KenyanTaxCalculator;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

/**
 * Proves runtime overrides actually flow through the migrated call sites,
 * not just the resolver in isolation.
 */
class BillingSettingsIntegrationTest extends BaseTestCase
{
    private function settings(): BillingSettings
    {
        return $this->app()->make(BillingSettings::class);
    }

    private function manager(): PaymentManager
    {
        return $this->app()->make(PaymentManager::class);
    }

    // ── PaymentManager ────────────────────────────────────────────

    public function test_default_driver_follows_a_global_override(): void
    {
        $this->setConfig('billing.default_provider', 'manual');
        $this->assertSame('manual', $this->manager()->getDefaultDriver());

        $this->settings()->set('default_provider', 'paystack');

        $this->assertSame('paystack', $this->manager()->getDefaultDriver());
    }

    public function test_enabled_providers_follows_an_override(): void
    {
        // Configure two providers so they are eligible to appear at checkout.
        $this->setConfig('billing.providers.mpesa', [
            'consumer_key' => 'k', 'consumer_secret' => 's',
        ]);
        $this->setConfig('billing.providers.airtel', [
            'client_id' => 'k', 'client_secret' => 's',
        ]);
        $this->setConfig('billing.enabled_providers', []);

        $this->settings()->set('enabled_providers', ['airtel']);

        $this->assertSame(['airtel'], $this->manager()->getEnabledProviders());
    }

    public function test_offer_cash_override_hides_manual_from_payment_options(): void
    {
        $this->setConfig('billing.enabled_providers', []);

        $this->settings()->set('offer_cash', false);

        $providers = array_column($this->manager()->getPaymentOptions(), 'provider');

        $this->assertNotContains('manual', $providers);
    }

    public function test_provider_limit_override_cannot_exceed_config_ceiling(): void
    {
        $this->setConfig('billing.providers.mpesa.limits.max_amount', 25_000_000);
        $company = Company::create(['name' => 'Acme']);

        // A tenant override above the real provider cap is clamped down to the
        // config ceiling, so it can't be used to bypass split-payment limits.
        $this->settings()->set('providers.mpesa.limits.max_amount', 100_000_000, $company);

        $this->assertSame(25_000_000, $this->manager()->getProviderLimits('mpesa', $company)['max_amount']);

        // A tighter override is still honoured.
        $this->settings()->set('providers.mpesa.limits.max_amount', 5_000_000, $company);
        $this->assertSame(5_000_000, $this->manager()->getProviderLimits('mpesa', $company)['max_amount']);
    }

    public function test_provider_limit_resolves_global_then_per_tenant(): void
    {
        $this->setConfig('billing.providers.mpesa.limits.max_amount', 25_000_000);
        $company = Company::create(['name' => 'Acme']);

        // Global override applies to everyone.
        $this->settings()->set('providers.mpesa.limits.max_amount', 10_000_000);
        $this->assertSame(10_000_000, $this->manager()->getProviderLimits('mpesa')['max_amount']);

        // Per-tenant override wins for that tenant only.
        $this->settings()->set('providers.mpesa.limits.max_amount', 5_000_000, $company);
        $this->assertSame(5_000_000, $this->manager()->getProviderLimits('mpesa', $company)['max_amount']);
        $this->assertSame(10_000_000, $this->manager()->getProviderLimits('mpesa')['max_amount']);
    }

    // ── InvoiceService ────────────────────────────────────────────

    public function test_invoice_due_date_follows_a_due_days_override(): void
    {
        $company = Company::create(['name' => 'Acme']);
        $plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Pro',
            'slug' => 'pro',
            'base_price' => 10_000,
            'currency' => 'KES',
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => [],
        ]);
        $subscription = Subscription::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => Company::class,
            'billable_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->settings()->set('invoices.due_days', 10, $company);

        $service = new InvoiceService(new KenyanTaxCalculator);
        $invoice = $service->generateForSubscription($subscription);

        $this->assertNotNull($invoice->due_date);
        $this->assertSame(now()->addDays(10)->toDateString(), $invoice->due_date->toDateString());
    }

    // ── Helper + facade ───────────────────────────────────────────

    public function test_helper_resolves_per_billable_override(): void
    {
        $company = Company::create(['name' => 'Acme']);
        $this->setConfig('billing.currency', 'KES');

        BillingSettingsFacade::set('currency', 'USD', $company);

        $this->assertSame('USD', billing_setting('currency', 'KES', $company));
        $this->assertSame('KES', billing_setting('currency', 'KES'));
    }

    // ── Cache coherence + graceful degradation ────────────────────

    public function test_override_is_visible_to_a_fresh_resolver_after_set_with_caching_on(): void
    {
        $this->setConfig('billing.settings.cache_ttl', 300); // caching on
        $this->setConfig('billing.currency', 'KES');

        // Prime, then change through one instance.
        $this->assertSame('KES', $this->settings()->get('currency'));
        $this->settings()->set('currency', 'USD');

        // A brand-new resolver (empty memo) still sees the change: set() flushed
        // the shared cache key.
        $fresh = new BillingSettings($this->app()->make('cache'), $this->app()->make('config'));
        $this->assertSame('USD', $fresh->get('currency'));
    }

    public function test_reads_fall_back_to_config_when_settings_table_is_absent(): void
    {
        $this->setConfig('billing.currency', 'KES');

        Schema::drop((new Setting)->getTable());

        // No throw despite the missing table: degrade to the config default.
        $this->assertSame('KES', $this->settings()->get('currency', 'XXX'));
    }
}

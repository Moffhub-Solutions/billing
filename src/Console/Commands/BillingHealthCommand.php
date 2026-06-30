<?php

declare(strict_types=1);

namespace Moffhub\Billing\Console\Commands;

use Illuminate\Console\Command;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;

class BillingHealthCommand extends Command
{
    protected $signature = 'billing:health';

    protected $description = 'Check billing system health — plans, features, providers, subscriptions';

    public function handle(PaymentManager $paymentManager): int
    {
        $this->info('Billing Health Check');
        $this->line(str_repeat('─', 50));

        // Plans
        $planCount = Plan::count();
        $activePlans = Plan::where('is_active', true)->count();
        $this->line("Plans: {$activePlans} active / {$planCount} total");

        // Features
        $featureCount = Feature::count();
        $activeFeatures = Feature::where('is_active', true)->count();
        $addonCount = Feature::where('is_addon', true)->count();
        $this->line("Features: {$activeFeatures} active / {$featureCount} total ({$addonCount} add-ons)");

        // Subscriptions
        $totalSubs = Subscription::count();
        $activeSubs = Subscription::active()->count();
        $trialSubs = Subscription::where('status', 'trialing')->count();
        $this->line("Subscriptions: {$activeSubs} active, {$trialSubs} trialing / {$totalSubs} total");

        // Payment Providers
        $this->newLine();
        $this->info('Payment Providers:');

        foreach ($paymentManager->getAvailableProviders() as $provider) {
            $configured = $paymentManager->isProviderConfigured($provider);
            $isDefault = $provider === billing_setting('default_provider');
            $status = $configured ? '<fg=green>configured</>' : '<fg=red>not configured</>';
            $default = $isDefault ? ' <fg=yellow>(default)</>' : '';
            $this->line("  {$provider}: {$status}{$default}");
        }

        $this->newLine();
        $this->info('Configuration:');
        $currencyRaw = billing_setting('currency', 'KES');
        $cacheTtlRaw = billing_setting('features.cache_ttl', 300);
        $graceRaw = billing_setting('subscriptions.grace_period_days', 7);

        $this->line('  Currency: '.(is_string($currencyRaw) ? $currencyRaw : 'KES'));
        $this->line('  Feature cache TTL: '.(is_numeric($cacheTtlRaw) ? (int) $cacheTtlRaw : 300).'s');
        $this->line('  Grace period: '.(is_numeric($graceRaw) ? (int) $graceRaw : 7).' days');
        $this->line('  Webhooks: '.(config('billing.webhooks.enabled') ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }
}

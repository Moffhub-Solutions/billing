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
            $isDefault = $provider === config('billing.default_provider');
            $status = $configured ? '<fg=green>configured</>' : '<fg=red>not configured</>';
            $default = $isDefault ? ' <fg=yellow>(default)</>' : '';
            $this->line("  {$provider}: {$status}{$default}");
        }

        $this->newLine();
        $this->info('Configuration:');
        $this->line('  Currency: '.config('billing.currency', 'KES'));
        $this->line('  Feature cache TTL: '.config('billing.features.cache_ttl', 300).'s');
        $this->line('  Grace period: '.config('billing.subscriptions.grace_period_days', 7).' days');
        $this->line('  Webhooks: '.(config('billing.webhooks.enabled') ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }
}

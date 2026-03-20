<?php

declare(strict_types=1);

namespace Moffhub\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;

class SyncPlansCommand extends Command
{
    protected $signature = 'billing:sync-plans
        {--seed : Also seed default plan tiers}
        {--dry-run : Show what would be synced without making changes}';

    protected $description = 'Sync plan features from config to the database';

    public function handle(): int
    {
        if ($this->option('seed')) {
            return $this->seedDefaults();
        }

        $this->info('Syncing billing plans and features...');

        $plans = Plan::all();
        $features = Feature::all();

        $this->table(
            ['ID', 'Slug', 'Name', 'Price', 'Cycle', 'Active', 'Features'],
            $plans->map(fn (Plan $plan): array => [
                $plan->id,
                $plan->slug,
                $plan->name,
                number_format($plan->base_price / 100, 2),
                $plan->billing_cycle?->value ?? '-',
                $plan->is_active ? 'Yes' : 'No',
                count($plan->features ?? []),
            ])->toArray(),
        );

        $this->newLine();
        $this->info("Plans: {$plans->count()} | Features: {$features->count()}");

        return self::SUCCESS;
    }

    protected function seedDefaults(): int
    {
        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] Would seed default plans and features.');

            return self::SUCCESS;
        }

        $this->info('Seeding default features...');

        $defaultFeatures = [
            ['slug' => 'gatebook', 'name' => 'Core Gatebook', 'type' => 'boolean', 'category' => 'core'],
            ['slug' => 'incidents', 'name' => 'Incident Reporting', 'type' => 'boolean', 'category' => 'core'],
            ['slug' => 'shifts', 'name' => 'Shift Management', 'type' => 'boolean', 'category' => 'operations'],
            ['slug' => 'ocr_scanning', 'name' => 'Kenya ID OCR Scanning', 'type' => 'metered', 'category' => 'addons', 'is_addon' => true, 'addon_price' => 2000],
            ['slug' => 'visitor_preregistration', 'name' => 'Visitor Pre-registration', 'type' => 'boolean', 'category' => 'addons', 'is_addon' => true, 'addon_price' => 1500],
            ['slug' => 'analytics_reports', 'name' => 'Advanced Analytics & Reports', 'type' => 'boolean', 'category' => 'addons', 'is_addon' => true, 'addon_price' => 3000],
            ['slug' => 'hr_management', 'name' => 'HR Management', 'type' => 'boolean', 'category' => 'addons', 'is_addon' => true, 'addon_price' => 5000],
            ['slug' => 'vehicle_management', 'name' => 'Vehicle Tracking', 'type' => 'boolean', 'category' => 'addons', 'is_addon' => true, 'addon_price' => 2500],
            ['slug' => 'webhooks', 'name' => 'Webhook Integrations', 'type' => 'boolean', 'category' => 'addons', 'is_addon' => true, 'addon_price' => 2000],
            ['slug' => 'bulk_import', 'name' => 'CSV Bulk Import', 'type' => 'boolean', 'category' => 'addons', 'is_addon' => true, 'addon_price' => 1000],
        ];

        foreach ($defaultFeatures as $feature) {
            Feature::updateOrCreate(
                ['slug' => $feature['slug']],
                array_merge($feature, ['is_active' => true]),
            );
        }

        $this->info('Seeding default plans...');

        $defaultPlans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For small sites getting started',
                'base_price' => 250000, // KES 2,500
                'billing_cycle' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 1,
                'features' => ['gatebook', 'incidents'],
                'limits' => ['max_posts' => 2, 'max_guards' => 5, 'max_entries_per_month' => 500],
            ],
            [
                'name' => 'Standard',
                'slug' => 'standard',
                'description' => 'For growing security operations',
                'base_price' => 750000, // KES 7,500
                'billing_cycle' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 2,
                'features' => ['gatebook', 'incidents', 'shifts', 'analytics_reports', 'vehicle_management'],
                'limits' => ['max_posts' => 10, 'max_guards' => 25, 'max_entries_per_month' => 5000, 'ocr_scanning' => 100],
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'For established security companies',
                'base_price' => 1500000, // KES 15,000
                'billing_cycle' => 'monthly',
                'sort_order' => 3,
                'features' => ['gatebook', 'incidents', 'shifts', 'analytics_reports', 'vehicle_management', 'hr_management', 'webhooks', 'bulk_import'],
                'limits' => ['ocr_scanning' => 500],
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'For large-scale operations — all features included',
                'base_price' => 3500000, // KES 35,000
                'billing_cycle' => 'monthly',
                'sort_order' => 4,
                'features' => ['gatebook', 'incidents', 'shifts', 'ocr_scanning', 'visitor_preregistration', 'analytics_reports', 'hr_management', 'vehicle_management', 'webhooks', 'bulk_import'],
                'limits' => [], // unlimited
            ],
        ];

        foreach ($defaultPlans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                array_merge($plan, ['ulid' => Str::ulid()->toBase32(), 'is_active' => true]),
            );
        }

        $this->info('Seeded '.count($defaultFeatures).' features and '.count($defaultPlans).' plans.');

        return self::SUCCESS;
    }
}

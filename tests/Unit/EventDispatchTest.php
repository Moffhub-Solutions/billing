<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\InvoiceGenerated;
use Moffhub\Billing\Events\SubscriptionCreated;
use Moffhub\Billing\Events\SubscriptionPaused;
use Moffhub\Billing\Events\SubscriptionResumed;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Services\InvoiceService;
use Moffhub\Billing\Services\KenyanTaxCalculator;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class EventDispatchTest extends BaseTestCase
{
    private Company $company;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Company']);

        $this->plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 500000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook'],
            'limits' => [],
        ]);
    }

    public function test_subscription_created_dispatched_with_enriched_data(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $subscription = $this->company->subscribe('standard')
            ->trialDays(14)
            ->create();

        Event::assertDispatched(SubscriptionCreated::class, function (SubscriptionCreated $event) use ($subscription) {
            return $event->subscription->id === $subscription->id
                && $event->billable->id === $this->company->id
                && $event->plan->id === $this->plan->id
                && $event->trialEndsAt !== null;
        });
    }

    public function test_subscription_created_without_trial(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $this->company->subscribe('standard')->create();

        Event::assertDispatched(SubscriptionCreated::class, function (SubscriptionCreated $event) {
            return $event->trialEndsAt === null
                && $event->billable->id === $this->company->id
                && $event->plan->slug === 'standard';
        });
    }

    public function test_subscription_paused_dispatched_on_pause(): void
    {
        Event::fake([SubscriptionPaused::class]);

        $subscription = $this->createSubscription(SubscriptionStatus::ACTIVE);
        $subscription->pause();

        Event::assertDispatched(SubscriptionPaused::class, function (SubscriptionPaused $event) use ($subscription) {
            return $event->subscription->id === $subscription->id
                && $event->billable->id === $this->company->id
                && $event->plan->id === $this->plan->id;
        });
    }

    public function test_subscription_resumed_dispatched_on_resume(): void
    {
        Event::fake([SubscriptionResumed::class]);

        $subscription = $this->createSubscription(SubscriptionStatus::PAUSED);
        $subscription->resume();

        Event::assertDispatched(SubscriptionResumed::class, function (SubscriptionResumed $event) use ($subscription) {
            return $event->subscription->id === $subscription->id
                && $event->billable->id === $this->company->id
                && $event->plan->id === $this->plan->id;
        });
    }

    public function test_invoice_generated_dispatched_on_invoice_creation(): void
    {
        Event::fake([InvoiceGenerated::class]);

        $subscription = $this->createSubscription(SubscriptionStatus::ACTIVE);

        $service = new InvoiceService(new KenyanTaxCalculator);
        $invoice = $service->generateForSubscription($subscription);

        Event::assertDispatched(InvoiceGenerated::class, function (InvoiceGenerated $event) use ($invoice) {
            return $event->invoice->id === $invoice->id
                && $event->billable->id === $this->company->id
                && $event->invoiceNumber === $invoice->number
                && $event->total === $invoice->total
                && $event->currency === $invoice->currency
                && $event->dueDate !== null;
        });
    }

    private function createSubscription(SubscriptionStatus $status): Subscription
    {
        return $this->company->subscriptions()->create([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $this->plan->id,
            'status' => $status,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
            'paused_at' => $status === SubscriptionStatus::PAUSED ? now() : null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\SubscriptionCreated;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;

class SubscriptionBuilder
{
    protected ?int $trialDays = null;

    protected ?string $paymentProvider = null;

    protected ?string $providerSubscriptionId = null;

    protected array $metadata = [];

    public function __construct(
        protected Model $billable,
        protected string $planSlug,
    ) {}

    /**
     * Set the number of trial days.
     */
    public function trialDays(int $days): self
    {
        $this->trialDays = $days;

        return $this;
    }

    /**
     * Set the payment provider.
     */
    public function provider(string $provider, ?string $providerSubscriptionId = null): self
    {
        $this->paymentProvider = $provider;
        $this->providerSubscriptionId = $providerSubscriptionId;

        return $this;
    }

    /**
     * Set metadata on the subscription.
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Create the subscription.
     */
    public function create(): Subscription
    {
        $plan = Plan::where('slug', $this->planSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $trialDays = $this->trialDays ?? $plan->trial_days;
        $isTrialing = $trialDays !== null && $trialDays > 0;

        $subscription = new Subscription([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $plan->id,
            'status' => $isTrialing ? SubscriptionStatus::TRIALING : SubscriptionStatus::ACTIVE,
            'trial_ends_at' => $isTrialing ? now()->addDays($trialDays) : null,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($plan->billing_cycle->days()),
            'payment_provider' => $this->paymentProvider,
            'provider_subscription_id' => $this->providerSubscriptionId,
            'metadata' => $this->metadata ?: null,
        ]);

        $this->billable->subscriptions()->save($subscription);

        SubscriptionCreated::dispatch(
            $subscription,
            $this->billable,
            $plan,
            $subscription->trial_ends_at,
        );

        return $subscription;
    }
}

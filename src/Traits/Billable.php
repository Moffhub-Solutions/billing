<?php

declare(strict_types=1);

namespace Moffhub\Billing\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Moffhub\Billing\Models\CouponRedemption;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\PaymentToken;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Models\UsageRecord;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\PayOrchestraProvider;
use Moffhub\Billing\Services\SubscriptionBuilder;

trait Billable
{
    /**
     * Start building a new subscription.
     */
    public function subscribe(string $planSlug): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $planSlug);
    }

    /**
     * Get the active subscription.
     */
    public function subscription(): ?Subscription
    {
        return $this->subscriptions()
            ->active()
            ->latest()
            ->first();
    }

    /**
     * Get all subscriptions.
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable');
    }

    /**
     * Get all payments.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'billable');
    }

    /**
     * Get all usage records.
     */
    public function usageRecords(): MorphMany
    {
        return $this->morphMany(UsageRecord::class, 'billable');
    }

    /**
     * Get all saved payment tokens/methods.
     */
    public function paymentTokens(): MorphMany
    {
        return $this->morphMany(PaymentToken::class, 'billable');
    }

    /**
     * Get the default payment token.
     */
    public function defaultPaymentToken(): ?PaymentToken
    {
        return $this->paymentTokens()->default()->first();
    }

    /**
     * Get all coupon redemptions.
     */
    public function couponRedemptions(): MorphMany
    {
        return $this->morphMany(CouponRedemption::class, 'billable');
    }

    /**
     * Check if the billable has any active subscription.
     */
    public function subscribed(): bool
    {
        return $this->subscription() !== null;
    }

    /**
     * Check if the billable is on a specific plan.
     */
    public function onPlan(string $planSlug): bool
    {
        $subscription = $this->subscription();

        return $subscription !== null && $subscription->plan->slug === $planSlug;
    }

    /**
     * Check if the billable is currently on a trial.
     */
    public function onTrial(): bool
    {
        $subscription = $this->subscription();

        return $subscription !== null && $subscription->onTrial();
    }

    /**
     * Check if the billable has access to a specific feature.
     * Checks plan features + active add-ons.
     */
    public function hasFeature(string $featureSlug): bool
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return false;
        }

        return $subscription->hasFeature($featureSlug);
    }

    /**
     * Check if the billable has access to a feature, or is an admin that bypasses gating.
     *
     * The admin check method is configured via `billing.admin_bypass_method`.
     * If the method exists on this model and returns true, feature access is granted
     * regardless of subscription status.
     */
    public function hasFeatureOrAdmin(string $featureSlug): bool
    {
        if ($this->isBillingAdmin()) {
            return true;
        }

        return $this->hasFeature($featureSlug);
    }

    /**
     * Check if this billable is considered an admin for billing bypass purposes.
     */
    public function isBillingAdmin(): bool
    {
        $method = config('billing.admin_bypass_method');

        if ($method === null) {
            return false;
        }

        return method_exists($this, $method) && $this->{$method}() === true;
    }

    /**
     * Get the current period usage count for a metered feature.
     */
    public function usage(string $featureSlug): int
    {
        $record = $this->usageRecords()
            ->where('feature_slug', $featureSlug)
            ->currentPeriod()
            ->first();

        return $record?->usage_count ?? 0;
    }

    /**
     * Get the usage limit for a feature in the current plan.
     */
    public function usageLimit(string $featureSlug): ?int
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return 0;
        }

        return $subscription->plan->getLimit($featureSlug);
    }

    /**
     * Get remaining quota for a metered feature.
     */
    public function remainingQuota(string $featureSlug): ?int
    {
        $limit = $this->usageLimit($featureSlug);

        if ($limit === null) {
            return null; // unlimited
        }

        return max(0, $limit - $this->usage($featureSlug));
    }

    /**
     * Get usage percentage (0.0 to 1.0+) for a metered feature.
     */
    public function usagePercentage(string $featureSlug): ?float
    {
        $limit = $this->usageLimit($featureSlug);

        if ($limit === null || $limit === 0) {
            return null;
        }

        return $this->usage($featureSlug) / $limit;
    }

    /**
     * Charge via the PayOrchestra backbone with an explicit channel hint.
     *
     * The PayOrchestra driver routes the request to the appropriate connector
     * (M-Pesa STK push, card processor, bank transfer, etc.) based on the
     * channel and the rules configured in the PayOrchestra control plane.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function chargeVia(string $channel, int $amount, string $currency, array $options = []): array
    {
        $options['channel'] = $channel;
        $options['metadata'] = array_merge($options['metadata'] ?? [], [
            'billable_type' => static::class,
            'billable_id' => $this->getKey(),
        ]);

        return app(PaymentManager::class)
            ->driver('payorchestra')
            ->charge($amount, $currency, $options);
    }

    /**
     * Create a PayOrchestra hosted payment session.
     *
     * Returns a URL the consumer can redirect the payer to, where PayOrchestra
     * presents a hosted checkout that supports every installed channel.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, session_url: string|null, session_id: string|null, expires_at: string|null}
     */
    public function hostedPayment(int $amount, string $currency, array $options = []): array
    {
        $provider = app(PaymentManager::class)->driver('payorchestra');

        if (! $provider instanceof PayOrchestraProvider) {
            throw new \RuntimeException('Hosted payments require the payorchestra driver.');
        }

        $options['metadata'] = array_merge($options['metadata'] ?? [], [
            'billable_type' => static::class,
            'billable_id' => $this->getKey(),
        ]);

        return $provider->createHostedSession($amount, $currency, $options);
    }
}

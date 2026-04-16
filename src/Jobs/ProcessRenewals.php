<?php

declare(strict_types=1);

namespace Moffhub\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\UsageService;

class ProcessRenewals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PaymentManager $paymentManager): void
    {
        // Cancelled subscriptions with `cancel(immediately: false)` keep
        // `status = ACTIVE` until the period ends. Without the cancelled_at
        // filter, this job would happily charge a customer who explicitly
        // cancelled — see `Subscription::onGracePeriod()`.
        $subscriptions = Subscription::where('status', SubscriptionStatus::ACTIVE)
            ->whereNull('cancelled_at')
            ->where('current_period_end', '<=', now())
            ->with(['plan', 'billable'])
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->processRenewal($subscription, $paymentManager);
        }
    }

    protected function processRenewal(Subscription $subscription, PaymentManager $paymentManager): void
    {
        $plan = $subscription->plan;
        $provider = $subscription->payment_provider ?? $paymentManager->getDefaultDriver();
        $currency = config('billing.currency', 'KES');
        $amount = $this->renewalAmount($subscription);

        try {
            $driver = $paymentManager->driver($provider);
            $result = $driver->charge($amount, $currency, [
                'subscription_id' => $subscription->id,
                'description' => "Renewal for {$plan->name}",
            ]);

            if ($result['success']) {
                $cycleDays = $plan->billing_cycle->days();

                $subscription->update([
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays($cycleDays),
                ]);

                $payment = new Payment([
                    'ulid' => Str::ulid()->toBase32(),
                    'subscription_id' => $subscription->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => PaymentStatus::COMPLETED,
                    'payment_provider' => $provider,
                    'provider_payment_id' => $result['provider_payment_id'] ?? null,
                    'provider_reference' => $result['provider_reference'] ?? null,
                    'paid_at' => now(),
                    'metadata' => $result['metadata'] ?? null,
                ]);

                $subscription->billable->payments()->save($payment);

                SubscriptionRenewed::dispatch(
                    $subscription,
                    $subscription->billable,
                    $plan,
                    $subscription->current_period_start,
                    $subscription->current_period_end,
                    $amount,
                    $currency,
                );

                // Reset usage for the new period
                $this->resetUsage($subscription);
            } else {
                $this->handleFailure($subscription, $provider, $currency, $amount, $result);
            }
        } catch (\Throwable $e) {
            Log::error("Billing: Renewal failed for subscription {$subscription->id}", [
                'error' => $e->getMessage(),
            ]);

            $this->handleFailure($subscription, $provider, $currency, $amount, [
                'metadata' => ['error' => $e->getMessage()],
            ]);
        }
    }

    /**
     * Resolve the amount to charge for a renewal.
     *
     * Variable-priced subscriptions (per-stream, per-seat, per-MRR-tier) can
     * persist their billable amount in `metadata.amount` at subscription
     * creation. Fall back to the plan's base_price for flat-rate plans.
     */
    protected function renewalAmount(Subscription $subscription): int
    {
        $override = $subscription->metadata['amount'] ?? null;

        if (is_int($override) || (is_numeric($override) && (int) $override == $override)) {
            return (int) $override;
        }

        return (int) $subscription->plan->base_price;
    }

    protected function handleFailure(Subscription $subscription, string $provider, string $currency, int $amount, array $result): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::PAST_DUE,
        ]);

        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::FAILED,
            'payment_provider' => $provider,
            'failed_at' => now(),
            'metadata' => $result['metadata'] ?? null,
        ]);

        $subscription->billable->payments()->save($payment);

        PaymentFailed::dispatch(
            $payment,
            $subscription->billable,
            $amount,
            $currency,
            'Renewal charge failed',
        );
    }

    protected function resetUsage(Subscription $subscription): void
    {
        $billable = $subscription->billable;
        $limits = $subscription->plan->limits ?? [];

        $usageService = app(UsageService::class);

        foreach (array_keys($limits) as $featureSlug) {
            $usageService->resetUsage($billable, $featureSlug);
        }
    }
}

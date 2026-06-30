<?php

declare(strict_types=1);

namespace Moffhub\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\UsageService;

class ProcessRenewals implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PaymentManager $paymentManager): void
    {
        // Cancelled subscriptions with `cancel(immediately: false)` keep
        // `status = ACTIVE` until the period ends. Without the cancelled_at
        // filter, this job would happily charge a customer who explicitly
        // cancelled — see `Subscription::onGracePeriod()`.
        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE)
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
        // Re-check under a row lock that the subscription is still due. A queue
        // retry (after a charge already succeeded and advanced the period) or an
        // overlapping run then finds the period in the future and skips, so the
        // customer is not charged twice.
        $stillDue = DB::transaction(function () use ($subscription): bool {
            $locked = Subscription::query()->lockForUpdate()->find($subscription->id);

            return $locked instanceof Subscription
                && $locked->status === SubscriptionStatus::ACTIVE
                && $locked->cancelled_at === null
                && $locked->current_period_end !== null
                && $locked->current_period_end->lessThanOrEqualTo(now());
        });

        if (! $stillDue) {
            return;
        }

        $plan = $subscription->plan;
        $provider = $subscription->payment_provider ?? $paymentManager->getDefaultDriver();
        // Charge in the plan's own currency (what the subscription is priced in),
        // not a global setting, so the charged amount and currency can't diverge.
        $currency = $plan->currency !== '' ? $plan->currency : 'KES';
        $amount = $this->renewalAmount($subscription);

        try {
            $driver = $paymentManager->driver($provider);
            if (! $driver instanceof PaymentProviderInterface) {
                throw new \RuntimeException("driver({$provider}) did not resolve to PaymentProviderInterface.");
            }
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
                    'metadata' => $result['metadata'],
                ]);

                $billable = $subscription->billable;
                $billable->morphMany(Payment::class, 'billable')->save($payment);

                $periodStart = $subscription->current_period_start ?? now();
                $periodEnd = $subscription->current_period_end ?? now();

                SubscriptionRenewed::dispatch(
                    $subscription,
                    $subscription->billable,
                    $plan,
                    $periodStart,
                    $periodEnd,
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
        $metadata = $subscription->metadata ?? [];
        $override = $metadata['amount'] ?? null;

        if (is_int($override)) {
            return $override;
        }

        if (is_numeric($override) && (int) $override == $override) {
            return (int) $override;
        }

        return $subscription->plan->base_price;
    }

    /**
     * @param  array<string, mixed>  $result
     */
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
            'metadata' => $result['metadata'],
        ]);

        $billable = $subscription->billable;
        $billable->morphMany(Payment::class, 'billable')->save($payment);

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
            $usageService->resetUsage($billable, (string) $featureSlug);
        }
    }
}

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
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;

class ProcessDunning implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PaymentManager $paymentManager): void
    {
        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::PAST_DUE)
            ->with(['plan', 'billable'])
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->processDunning($subscription, $paymentManager);
        }
    }

    protected function processDunning(Subscription $subscription, PaymentManager $paymentManager): void
    {
        $dunningSchedule = $this->dunningSchedule();
        $gracePeriodRaw = config('billing.subscriptions.grace_period_days', 7);
        $gracePeriodDays = is_numeric($gracePeriodRaw) ? (int) $gracePeriodRaw : 7;

        // Determine when the subscription became past_due by looking at the period end
        $failedAt = $subscription->current_period_end;

        if ($failedAt === null) {
            return;
        }

        $daysSinceFailure = (int) $failedAt->diffInDays(now());

        // Check if today matches a dunning schedule day
        if (! in_array($daysSinceFailure, $dunningSchedule, true)) {
            // Check if all retries are exhausted and grace period has passed
            $maxRetryDay = $dunningSchedule === [] ? 0 : max($dunningSchedule);

            if ($daysSinceFailure > $maxRetryDay + $gracePeriodDays) {
                $subscription->update([
                    'status' => SubscriptionStatus::CANCELLED,
                    'cancelled_at' => now(),
                ]);

                $cancelledAt = $subscription->cancelled_at;

                if ($cancelledAt !== null) {
                    SubscriptionCancelled::dispatch(
                        $subscription,
                        $subscription->billable,
                        $subscription->plan,
                        $cancelledAt,
                        $subscription->current_period_end,
                        true,
                    );
                }
            }

            return;
        }

        $plan = $subscription->plan;
        $provider = $subscription->payment_provider ?? $paymentManager->getDefaultDriver();
        $currencyRaw = config('billing.currency', 'KES');
        $currency = is_string($currencyRaw) ? $currencyRaw : 'KES';

        try {
            $driver = $paymentManager->driver($provider);
            if (! $driver instanceof PaymentProviderInterface) {
                throw new \RuntimeException("driver({$provider}) did not resolve to PaymentProviderInterface.");
            }
            $result = $driver->charge($plan->base_price, $currency, [
                'subscription_id' => $subscription->id,
                'description' => "Dunning retry for {$plan->name} (day {$daysSinceFailure})",
            ]);

            if ($result['success']) {
                $cycleDays = $plan->billing_cycle->days();

                $subscription->update([
                    'status' => SubscriptionStatus::ACTIVE,
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays($cycleDays),
                ]);

                $payment = new Payment([
                    'ulid' => Str::ulid()->toBase32(),
                    'subscription_id' => $subscription->id,
                    'amount' => $plan->base_price,
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
                    $plan->base_price,
                    $currency,
                );
            } else {
                $this->handleRetryFailure($subscription, $provider, $currency, $result, $daysSinceFailure, $dunningSchedule, $gracePeriodDays);
            }
        } catch (\Throwable $e) {
            Log::error("Billing: Dunning retry failed for subscription {$subscription->id}", [
                'error' => $e->getMessage(),
                'day' => $daysSinceFailure,
            ]);

            $this->handleRetryFailure($subscription, $provider, $currency, [
                'metadata' => ['error' => $e->getMessage()],
            ], $daysSinceFailure, $dunningSchedule, $gracePeriodDays);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, int>  $dunningSchedule
     */
    protected function handleRetryFailure(
        Subscription $subscription,
        string $provider,
        string $currency,
        array $result,
        int $daysSinceFailure,
        array $dunningSchedule,
        int $gracePeriodDays,
    ): void {
        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'subscription_id' => $subscription->id,
            'amount' => $subscription->plan->base_price,
            'currency' => $currency,
            'status' => PaymentStatus::FAILED,
            'payment_provider' => $provider,
            'failed_at' => now(),
            'metadata' => $result['metadata'],
        ]);

        $billable = $subscription->billable;
        $billable->morphMany(Payment::class, 'billable')->save($payment);

        // Count how many retries have been attempted
        $retryIndex = array_search($daysSinceFailure, $dunningSchedule, true);
        $retryCount = is_int($retryIndex) ? $retryIndex + 1 : count($dunningSchedule);

        PaymentFailed::dispatch(
            $payment,
            $subscription->billable,
            $subscription->plan->base_price,
            $currency,
            "Dunning retry failed (day {$daysSinceFailure})",
            $retryCount,
        );

        // If this was the last retry and grace period has passed, cancel
        if ($dunningSchedule === []) {
            return;
        }

        $maxRetryDay = max($dunningSchedule);

        if ($daysSinceFailure >= $maxRetryDay && $daysSinceFailure >= $maxRetryDay + $gracePeriodDays) {
            $subscription->update([
                'status' => SubscriptionStatus::CANCELLED,
                'cancelled_at' => now(),
            ]);

            $cancelledAt = $subscription->cancelled_at;

            if ($cancelledAt !== null) {
                SubscriptionCancelled::dispatch(
                    $subscription,
                    $subscription->billable,
                    $subscription->plan,
                    $cancelledAt,
                    $subscription->current_period_end,
                    true,
                );
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function dunningSchedule(): array
    {
        $raw = config('billing.subscriptions.dunning_schedule', [1, 3, 7]);

        if (! is_array($raw)) {
            return [1, 3, 7];
        }

        $ints = [];
        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $ints[] = (int) $value;
            }
        }

        return $ints;
    }
}

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
        $subscriptions = Subscription::where('status', SubscriptionStatus::ACTIVE)
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

        try {
            $driver = $paymentManager->driver($provider);
            $result = $driver->charge($plan->base_price, $currency, [
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
                    'amount' => $plan->base_price,
                    'currency' => $currency,
                    'status' => PaymentStatus::COMPLETED,
                    'payment_provider' => $provider,
                    'provider_payment_id' => $result['provider_payment_id'] ?? null,
                    'provider_reference' => $result['provider_reference'] ?? null,
                    'paid_at' => now(),
                    'metadata' => $result['metadata'] ?? null,
                ]);

                $subscription->billable->payments()->save($payment);

                SubscriptionRenewed::dispatch($subscription);

                // Reset usage for the new period
                $this->resetUsage($subscription);
            } else {
                $this->handleFailure($subscription, $provider, $currency, $result);
            }
        } catch (\Throwable $e) {
            Log::error("Billing: Renewal failed for subscription {$subscription->id}", [
                'error' => $e->getMessage(),
            ]);

            $this->handleFailure($subscription, $provider, $currency, [
                'metadata' => ['error' => $e->getMessage()],
            ]);
        }
    }

    protected function handleFailure(Subscription $subscription, string $provider, string $currency, array $result): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::PAST_DUE,
        ]);

        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'subscription_id' => $subscription->id,
            'amount' => $subscription->plan->base_price,
            'currency' => $currency,
            'status' => PaymentStatus::FAILED,
            'payment_provider' => $provider,
            'failed_at' => now(),
            'metadata' => $result['metadata'] ?? null,
        ]);

        $subscription->billable->payments()->save($payment);

        PaymentFailed::dispatch($payment, 'Renewal charge failed');
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

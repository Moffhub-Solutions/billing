<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Subscription;

/**
 * Paying an overdue invoice by hand (a paybill payment, a verified proof of
 * payment, "mark as paid") settles the invoice but, on its own, leaves the
 * subscription past due or expired: the renewal and dunning jobs only move it
 * on a charge they made themselves. This closes that gap, so money received
 * for a subscription restores it whichever way it arrived.
 *
 * A subscription a provider bills itself is left alone: the provider's own
 * events set its period, and moving it here too would count the cycle twice.
 */
class SubscriptionRecovery
{
    public function __construct(private readonly PaystackSubscriptionService $paystack) {}

    /**
     * Returns true when the subscription was restored.
     */
    public function afterInvoicePaid(Invoice $invoice): bool
    {
        if ($invoice->status !== InvoiceStatus::PAID || $invoice->subscription_id === null) {
            return false;
        }

        $subscription = Subscription::query()->with('plan')->find($invoice->subscription_id);

        if (! $subscription instanceof Subscription
            || ! in_array($subscription->status, [SubscriptionStatus::PAST_DUE, SubscriptionStatus::EXPIRED], true)
            || $subscription->cancelled_at !== null
            || $this->paystack->isProviderBilled($subscription)) {
            return false;
        }

        $end = $subscription->current_period_end;

        // The period already runs on: only the status was holding access back.
        // Otherwise the invoice paid for the next cycle, counted from now.
        if ($end === null || $end->isPast()) {
            $subscription->current_period_start = now();
            $subscription->current_period_end = now()->addDays($subscription->plan->billing_cycle->days());
        }

        $subscription->status = SubscriptionStatus::ACTIVE;
        $subscription->save();

        return true;
    }
}

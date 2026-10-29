<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Events\SubscriptionCreated;
use Moffhub\Billing\Events\SubscriptionExpired;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\PaystackProvider;

/**
 * Subscriptions that Paystack bills itself (cards; Paystack does not recur
 * M-Pesa or other mobile money).
 *
 * The local `Subscription` stays the one record the app reads, whichever way
 * it is paid. For these subscriptions Paystack runs the schedule and sends
 * webhooks, and this service turns each one into the same changes the
 * package's own renewals and M-Pesa standing orders make: the period moves
 * forward, a Payment is recorded, and the same events are dispatched.
 * `ProcessRenewals` leaves them alone, so a customer is never charged twice.
 */
class PaystackSubscriptionService
{
    /** `metadata.billing_mode` of a subscription Paystack bills. */
    public const MODE = 'provider';

    public function __construct(protected PaymentManager $payments) {}

    public function provider(): PaystackProvider
    {
        $driver = $this->payments->driver('paystack');

        if (! $driver instanceof PaystackProvider) {
            throw new \RuntimeException('driver(paystack) did not resolve to PaystackProvider.');
        }

        return $driver;
    }

    public static function intervalFor(BillingCycle $cycle): string
    {
        return match ($cycle) {
            BillingCycle::MONTHLY => 'monthly',
            BillingCycle::QUARTERLY => 'quarterly',
            BillingCycle::ANNUAL => 'annually',
        };
    }

    public function planCode(Plan $plan): ?string
    {
        $code = ($plan->metadata ?? [])['paystack_plan_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function isProviderBilled(Subscription $subscription): bool
    {
        return $subscription->payment_provider === 'paystack'
            && (($subscription->metadata ?? [])['billing_mode'] ?? null) === self::MODE;
    }

    /**
     * Create the plan on Paystack, or update it when its price or cycle
     * changed since the last sync. Changing a plan never touches existing
     * subscriptions unless `$updateExisting` is set.
     *
     * @return array{action: string, plan_code: string|null, message: string|null}
     */
    public function syncPlan(Plan $plan, bool $updateExisting = false): array
    {
        $interval = self::intervalFor($plan->billing_cycle);
        $provider = $this->provider();
        $metadata = $plan->metadata ?? [];
        $code = $this->planCode($plan);

        if ($code === null) {
            $result = $provider->createPlan($plan->name, $plan->base_price, $interval, $plan->currency, $plan->description);

            if (! $result['success'] || $result['plan_code'] === null) {
                return ['action' => 'failed', 'plan_code' => null, 'message' => $result['message']];
            }

            $this->rememberPlan($plan, $metadata, $result['plan_code'], $interval);

            return ['action' => 'created', 'plan_code' => $result['plan_code'], 'message' => null];
        }

        $unchanged = ($metadata['paystack_plan_amount'] ?? null) === $plan->base_price
            && ($metadata['paystack_plan_interval'] ?? null) === $interval;

        if ($unchanged) {
            return ['action' => 'unchanged', 'plan_code' => $code, 'message' => null];
        }

        $result = $provider->updatePlan($code, $plan->name, $plan->base_price, $interval, $plan->description, $updateExisting);

        if (! $result['success']) {
            return ['action' => 'failed', 'plan_code' => $code, 'message' => $result['message']];
        }

        $this->rememberPlan($plan, $metadata, $code, $interval);

        return ['action' => 'updated', 'plan_code' => $code, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function rememberPlan(Plan $plan, array $metadata, string $code, string $interval): void
    {
        $plan->metadata = [
            ...$metadata,
            'paystack_plan_code' => $code,
            'paystack_plan_amount' => $plan->base_price,
            'paystack_plan_interval' => $interval,
        ];
        $plan->save();
    }

    /**
     * Start a card subscription: the customer pays the first cycle on
     * Paystack's page and Paystack bills every cycle after. The local
     * subscription is created when that first payment is confirmed.
     *
     * @param  array<string, mixed>  $options  `callback_url`, `reference`
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function start(Model $billable, string $planSlug, string $email, array $options = []): array
    {
        $plan = Plan::query()->where('slug', $planSlug)->where('is_active', true)->firstOrFail();
        $code = $this->planCode($plan);

        if ($code === null) {
            throw new \RuntimeException("Plan {$plan->slug} is not on Paystack yet. Run billing:sync-plans --paystack.");
        }

        return $this->provider()->initializeTransaction($email, $plan->base_price, $plan->currency, [
            'plan' => $code,
            // Paystack can only bill a card again. Offering M-Pesa here would
            // take the first payment and then never bill the customer again.
            'channels' => ['card'],
            'callback_url' => $options['callback_url'] ?? null,
            'reference' => $options['reference'] ?? null,
            'metadata' => [
                'moffhub_billing' => [
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => $billable->getKey(),
                    'plan_slug' => $plan->slug,
                    'email' => strtolower($email),
                ],
            ],
        ]);
    }

    /**
     * Stop Paystack charging this subscription. True when there was nothing
     * to stop (not billed by Paystack) or Paystack accepted the request.
     */
    public function disable(Subscription $subscription): bool
    {
        if (! $this->isProviderBilled($subscription)) {
            return true;
        }

        $code = $subscription->provider_subscription_id;
        $token = $this->emailToken($subscription);

        if ($code === null || $token === null) {
            return false;
        }

        return $this->provider()->disableSubscription($code, $token);
    }

    /**
     * Safety net for a subscription Paystack bills: if its period ended well
     * ago and no renewal was reported (a missed webhook, a card Paystack could
     * not charge), it must not stay active for free. It goes past due, which
     * is what a failed renewal would have done.
     */
    public function lapseIfOverdue(Subscription $subscription): bool
    {
        $graceDays = config('billing.paystack.overdue_grace_days', 3);
        $end = $subscription->current_period_end;

        if (! $this->isProviderBilled($subscription) || $end === null || $subscription->status !== SubscriptionStatus::ACTIVE) {
            return false;
        }

        if ($end->greaterThan(now()->subDays(is_numeric($graceDays) ? (int) $graceDays : 3))) {
            return false;
        }

        $subscription->status = SubscriptionStatus::PAST_DUE;
        $subscription->save();

        Log::warning("Billing: no Paystack renewal was reported for subscription {$subscription->id}; marked past due");

        $plan = $subscription->plan;
        PaymentFailed::dispatch(null, $subscription->billable, $plan->base_price, $plan->currency, 'Paystack did not report a renewal');

        return true;
    }

    public function manageLink(Subscription $subscription): ?string
    {
        $code = $subscription->provider_subscription_id;

        return $this->isProviderBilled($subscription) && $code !== null
            ? $this->provider()->subscriptionManageLink($code)
            : null;
    }

    /**
     * Apply a verified, normalised Paystack event (PaystackProvider::parseWebhook)
     * to the local subscription. True when the event was a subscription event
     * and has been dealt with, so the caller need not settle it as a one-off payment.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): bool
    {
        $name = is_string($event['event'] ?? null) ? $event['event'] : '';
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        $info = is_array($metadata['subscription'] ?? null) ? $metadata['subscription'] : [];

        return match ($name) {
            'payment.completed' => $this->onCharge($event, $info),
            'subscription.created' => $this->onCreated($info),
            'subscription.payment_failed' => $this->onPaymentFailed($event, $info),
            'subscription.not_renewing' => $this->onNotRenewing($info),
            'subscription.disabled' => $this->onDisabled($info),
            default => false,
        };
    }

    /**
     * The first charge (carries our metadata, creates the local subscription)
     * or a renewal (Paystack's own charge for the plan, extends it).
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $info
     */
    protected function onCharge(array $event, array $info): bool
    {
        $planCode = $this->str($info['plan_code'] ?? null);

        // An ordinary one-off payment: not ours.
        if ($planCode === null) {
            return false;
        }

        $plan = $this->planByCode($planCode);
        $reference = $this->str($event['provider_payment_id'] ?? null);

        if ($plan === null || $reference === null) {
            return false;
        }

        // A redelivered webhook, or a payment already recorded.
        if (Payment::query()->where('provider_payment_id', $reference)->exists()) {
            return true;
        }

        $email = $this->str($info['customer_email'] ?? null);
        $transactionMeta = is_array($info['transaction_metadata'] ?? null) ? $info['transaction_metadata'] : [];
        $first = is_array($transactionMeta['moffhub_billing'] ?? null) ? $transactionMeta['moffhub_billing'] : null;

        $amount = is_int($event['amount'] ?? null) ? $event['amount'] : $plan->base_price;
        $currency = $this->str($event['currency'] ?? null) ?? $plan->currency;
        $eventMeta = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        $card = ($eventMeta['channel'] ?? null) === 'card';

        if (! $card && $first !== null) {
            Log::warning('Billing: first Paystack subscription payment was not by card, so Paystack will not bill it again', [
                'plan' => $planCode,
                'channel' => $eventMeta['channel'] ?? null,
                'reference' => $reference,
            ]);
        }

        /** @var Subscription|null $subscription */
        $subscription = null;
        $created = false;

        DB::transaction(function () use ($first, $email, $plan, $planCode, $reference, $amount, $currency, $card, &$subscription, &$created): void {
            $subscription = $this->locate(null, $email, $planCode, liveOnly: true);

            if ($subscription === null) {
                $billable = $first !== null ? $this->resolveBillable($first) : null;

                if ($billable === null) {
                    Log::warning('Billing: Paystack charge for a plan with no matching subscription', ['plan' => $planCode, 'reference' => $reference]);

                    return;
                }

                $subscription = new Subscription([
                    'ulid' => Str::ulid()->toBase32(),
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::ACTIVE,
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays($plan->billing_cycle->days()),
                    'payment_provider' => 'paystack',
                    'metadata' => $this->claimStash($email, $planCode, [
                        // Only a card can be billed again by Paystack. Anything
                        // else paid the first cycle but will not recur there, so
                        // it must not be left waiting for a charge that never comes.
                        'billing_mode' => $card ? self::MODE : 'self',
                        'customer_email' => $email,
                    ]),
                ]);
                $subscription->provider_subscription_id = $this->stashedCode($email, $planCode);
                $billable->morphMany(Subscription::class, 'billable')->save($subscription);
                $created = true;
            } else {
                $this->extend($subscription, $plan);
            }

            $payment = new Payment([
                'ulid' => Str::ulid()->toBase32(),
                'subscription_id' => $subscription->id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => PaymentStatus::COMPLETED,
                'payment_provider' => 'paystack',
                'provider_payment_id' => $reference,
                'paid_at' => now(),
                'metadata' => ['paystack_event' => 'charge.success', 'billing_mode' => self::MODE],
            ]);
            $subscription->billable->morphMany(Payment::class, 'billable')->save($payment);
        });

        if (! $subscription instanceof Subscription) {
            return true;
        }

        $subscription->loadMissing(['plan', 'billable']);
        $billable = $subscription->billable;
        $start = $subscription->current_period_start ?? now();
        $end = $subscription->current_period_end ?? now();

        if ($created) {
            SubscriptionCreated::dispatch($subscription, $billable, $plan, null);
        } else {
            SubscriptionRenewed::dispatch($subscription, $billable, $plan, $start, $end, $amount, $currency);
        }

        return true;
    }

    /**
     * Paystack says the subscription exists: remember its code and the token
     * needed to cancel it. It may arrive before or after the first charge, so
     * with no local subscription yet it is held for the charge to pick up.
     *
     * @param  array<string, mixed>  $info
     */
    protected function onCreated(array $info): bool
    {
        $code = $this->str($info['subscription_code'] ?? null);
        $token = $this->str($info['email_token'] ?? null);
        $email = $this->str($info['customer_email'] ?? null);
        $planCode = $this->str($info['plan_code'] ?? null);

        if ($code === null || $planCode === null) {
            return false;
        }

        $subscription = $this->locate($code, $email, $planCode, liveOnly: true);

        if ($subscription === null) {
            Cache::put($this->stashKey($email, $planCode), ['code' => $code, 'token' => $token, 'next' => $this->str($info['next_payment_date'] ?? null)], now()->addDay());

            return true;
        }

        $this->attach($subscription, $code, $token, $this->str($info['next_payment_date'] ?? null));

        return true;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $info
     */
    protected function onPaymentFailed(array $event, array $info): bool
    {
        $subscription = $this->locate($this->str($info['subscription_code'] ?? null), $this->str($info['customer_email'] ?? null), $this->str($info['plan_code'] ?? null));

        if ($subscription === null) {
            return false;
        }

        $subscription->status = SubscriptionStatus::PAST_DUE;
        $subscription->save();

        $plan = $subscription->plan;
        $amount = is_int($event['amount'] ?? null) ? $event['amount'] : $plan->base_price;
        $currency = $this->str($event['currency'] ?? null) ?? $plan->currency;

        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::FAILED,
            'payment_provider' => 'paystack',
            'failed_at' => now(),
            'metadata' => ['billing_mode' => self::MODE, 'paystack_event' => 'invoice.payment_failed'],
        ]);
        $subscription->billable->morphMany(Payment::class, 'billable')->save($payment);

        PaymentFailed::dispatch($payment, $subscription->billable, $amount, $currency, 'Renewal charge failed');

        return true;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    protected function onNotRenewing(array $info): bool
    {
        $subscription = $this->locate($this->str($info['subscription_code'] ?? null), $this->str($info['customer_email'] ?? null), $this->str($info['plan_code'] ?? null));

        if ($subscription === null) {
            return false;
        }

        if ($subscription->cancelled_at === null) {
            $subscription->cancelled_at = now();
            $subscription->save();

            SubscriptionCancelled::dispatch($subscription, $subscription->billable, $subscription->plan, now(), $subscription->current_period_end);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    protected function onDisabled(array $info): bool
    {
        $subscription = $this->locate($this->str($info['subscription_code'] ?? null), $this->str($info['customer_email'] ?? null), $this->str($info['plan_code'] ?? null));

        if ($subscription === null) {
            return false;
        }

        $completed = ($info['status'] ?? null) === 'complete';
        $wasCancelled = $subscription->cancelled_at !== null;

        $subscription->status = $completed ? SubscriptionStatus::EXPIRED : SubscriptionStatus::CANCELLED;
        $subscription->cancelled_at ??= now();
        $subscription->save();

        if ($completed) {
            SubscriptionExpired::dispatch($subscription, $subscription->billable, $subscription->plan, now());
        } elseif (! $wasCancelled) {
            SubscriptionCancelled::dispatch($subscription, $subscription->billable, $subscription->plan, now(), null, true);
        }

        return true;
    }

    /**
     * Move the period forward one cycle, the way an M-Pesa standing order
     * does: from the end of the current period when it has not passed yet,
     * otherwise from now. A successful charge also clears past-due.
     */
    protected function extend(Subscription $subscription, Plan $plan): void
    {
        $end = $subscription->current_period_end;
        $from = $end !== null && $end->isFuture() ? $end : now();

        $subscription->current_period_start = $end !== null && $end->isFuture() ? ($subscription->current_period_start ?? now()) : now();
        $subscription->current_period_end = $from->copy()->addDays($plan->billing_cycle->days());

        if ($subscription->status === SubscriptionStatus::PAST_DUE) {
            $subscription->status = SubscriptionStatus::ACTIVE;
        }

        $subscription->save();
    }

    protected function attach(Subscription $subscription, string $code, ?string $token, ?string $next): void
    {
        $metadata = $subscription->metadata ?? [];

        if ($token !== null) {
            $metadata['paystack_email_token'] = Crypt::encryptString($token);
        }

        $subscription->provider_subscription_id = $code;
        $subscription->metadata = $metadata;

        if ($next !== null) {
            try {
                $subscription->current_period_end = Carbon::parse($next);
            } catch (\Throwable) {
                // Keep the end we computed; the date is only a refinement.
            }
        }

        $subscription->save();
    }

    protected function emailToken(Subscription $subscription): ?string
    {
        $stored = ($subscription->metadata ?? [])['paystack_email_token'] ?? null;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function planByCode(string $planCode): ?Plan
    {
        return Plan::query()->get()->first(fn (Plan $plan): bool => $this->planCode($plan) === $planCode);
    }

    /**
     * The local subscription an event is about: by Paystack's code once it is
     * known, otherwise by the customer's email on that plan.
     */
    protected function locate(?string $code, ?string $email, ?string $planCode, bool $liveOnly = false): ?Subscription
    {
        if ($code !== null) {
            $byCode = Subscription::query()->where('payment_provider', 'paystack')->where('provider_subscription_id', $code)->first();

            if ($byCode instanceof Subscription) {
                return $byCode;
            }
        }

        if ($email === null || $planCode === null) {
            return null;
        }

        $plan = $this->planByCode($planCode);

        if ($plan === null) {
            return null;
        }

        return Subscription::query()
            ->where('payment_provider', 'paystack')
            ->where('plan_id', $plan->id)
            ->orderByDesc('id')
            ->get()
            ->first(function (Subscription $subscription) use ($email, $liveOnly): bool {
                $meta = $subscription->metadata ?? [];

                // A customer who cancelled and subscribes again starts a new
                // subscription, not a revival of the old one.
                if ($liveOnly && in_array($subscription->status, [SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED], true)) {
                    return false;
                }

                return ($meta['billing_mode'] ?? null) === self::MODE && ($meta['customer_email'] ?? null) === $email;
            });
    }

    /**
     * @param  array<mixed, mixed>  $meta
     */
    protected function resolveBillable(array $meta): ?Model
    {
        $type = $meta['billable_type'] ?? null;
        $id = $meta['billable_id'] ?? null;
        $allowed = config('billing.billable_model');

        if (! is_string($type) || ! is_string($allowed) || (! is_int($id) && ! is_string($id))) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_a($class, $allowed, true) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($id);
    }

    protected function stashKey(?string $email, string $planCode): string
    {
        return 'billing:paystack:subscription:'.sha1(($email ?? '').'|'.$planCode);
    }

    /**
     * A `subscription.create` that arrived before the first charge: its
     * details are picked up here, once, when the local subscription is made.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function claimStash(?string $email, string $planCode, array $metadata): array
    {
        $stash = Cache::get($this->stashKey($email, $planCode));

        if (is_array($stash) && is_string($stash['token'] ?? null)) {
            $metadata['paystack_email_token'] = Crypt::encryptString($stash['token']);
        }

        return $metadata;
    }

    protected function stashedCode(?string $email, string $planCode): ?string
    {
        $stash = Cache::pull($this->stashKey($email, $planCode));

        return is_array($stash) && is_string($stash['code'] ?? null) ? $stash['code'] : null;
    }

    protected function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

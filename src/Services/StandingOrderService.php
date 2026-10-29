<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\SupportsStandingOrders;
use Moffhub\Billing\Enums\PaymentMethod;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\StandingOrderFrequency;
use Moffhub\Billing\Enums\StandingOrderStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Events\StandingOrderActivated;
use Moffhub\Billing\Events\StandingOrderCancelled;
use Moffhub\Billing\Events\StandingOrderExecuted;
use Moffhub\Billing\Events\StandingOrderFailed;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Exceptions\StandingOrderException;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\StandingOrder;
use Moffhub\Billing\Models\StandingOrderExecution;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;

/**
 * Creates, tracks and cancels provider standing orders (M-Pesa Ratiba) for
 * subscriptions. A standing order carries only the fixed plan fee; metered usage
 * must be billed separately (see resolveAmountUsing() and the events).
 */
class StandingOrderService
{
    /** @var (Closure(Subscription, StandingOrderFrequency): int)|null */
    protected static ?Closure $amountResolver = null;

    public function __construct(protected PaymentManager $payments) {}

    /**
     * Let the host app decide the fixed amount (cents) of a subscription's
     * standing order, e.g. to add a flat add-on fee. Pass null to reset.
     *
     * @param  (Closure(Subscription, StandingOrderFrequency): int)|null  $resolver
     */
    public static function resolveAmountUsing(?Closure $resolver): void
    {
        static::$amountResolver = $resolver;
    }

    public function provider(string $name = 'mpesa'): SupportsStandingOrders
    {
        $driver = $this->payments->driver($name);

        if (! $driver instanceof SupportsStandingOrders) {
            throw new StandingOrderException("Provider [{$name}] does not support standing orders.");
        }

        return $driver;
    }

    /**
     * Create a pending standing order for a subscription. The customer must
     * still approve it on their phone; the order activates on the callback.
     *
     * Options: consent (required: true or ['at','ip','user_agent']), consent_ip,
     * consent_user_agent, amount (cents), frequency, name, start_date, end_date,
     * account_reference, description, provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function createForSubscription(Subscription $subscription, string $phone, array $options = []): StandingOrder
    {
        $subscription->loadMissing('plan');
        $plan = $subscription->plan;

        $frequency = $this->frequencyOption($options['frequency'] ?? null)
            ?? StandingOrderFrequency::fromBillingCycle($plan->billing_cycle);

        $amount = $options['amount'] ?? null;
        if (! is_int($amount)) {
            $amount = static::$amountResolver instanceof Closure
                ? (static::$amountResolver)($subscription, $frequency)
                : $this->defaultAmount($subscription);
        }

        if (($options['allow_multiple'] ?? false) !== true
            && StandingOrder::query()->open()->where('subscription_id', $subscription->id)->exists()) {
            throw new StandingOrderException('This subscription already has an open standing order.');
        }

        return $this->createOrder($subscription->billable, $subscription, $amount, $plan->currency !== '' ? $plan->currency : 'KES', $phone, [
            ...$options,
            'frequency' => $frequency,
            'description' => $options['description'] ?? $plan->name,
        ]);
    }

    /**
     * Create a pending standing order for any billable (no subscription).
     *
     * @param  array<string, mixed>  $options
     */
    public function create(Model $billable, int $amountCents, string $currency, string $phone, array $options = []): StandingOrder
    {
        return $this->createOrder($billable, null, $amountCents, $currency, $phone, $options);
    }

    /**
     * Standing order amounts are fixed, so a price change cancels the old order
     * and creates a new pending one (the customer approves it again). The new
     * order is created first so a rejected request leaves the old one intact.
     *
     * @param  array<string, mixed>  $options
     */
    public function changeAmount(StandingOrder $order, int $newAmountCents, array $options = []): StandingOrder
    {
        if ($order->amount === $newAmountCents && $order->status->isOpen()) {
            return $order;
        }

        $phone = $order->customer_phone;
        if ($phone === null || $phone === '') {
            throw new StandingOrderException('The original customer phone is unavailable; create a new standing order instead.');
        }

        $start = $order->next_expected_at !== null && $order->next_expected_at->isFuture()
            ? $order->next_expected_at->toDateString()
            : now()->toDateString();

        $new = $this->createOrder($order->billable, $order->subscription, $newAmountCents, $order->currency, $phone, [
            'frequency' => $order->frequency,
            'start_date' => $start,
            'end_date' => $order->end_date?->toDateString(),
            'provider' => $order->provider,
            'consent' => ['at' => $order->consent_at, 'ip' => $order->consent_ip, 'user_agent' => $order->consent_user_agent],
            'description' => $options['description'] ?? null,
            ...$options,
        ], ['replaces' => $order->ulid]);

        if ($new->status === StandingOrderStatus::Pending) {
            $this->cancel($order, 'amount_changed', true);
        }

        return $new;
    }

    /**
     * Cancel with the provider and locally. When the provider call fails the
     * method throws, unless $force is true: then the order is cancelled locally
     * and flagged `provider_cancel_pending` (the customer may need to cancel it
     * from their phone, or an operator must do so with Safaricom).
     */
    public function cancel(StandingOrder $order, string $reason = 'cancelled', bool $force = false): StandingOrder
    {
        if ($order->status === StandingOrderStatus::Cancelled) {
            return $order;
        }

        $providerPending = false;

        if ($order->provider_reference !== null && $order->status !== StandingOrderStatus::Failed) {
            try {
                $result = $this->provider($order->provider)->cancelStandingOrder($order->provider_reference);
            } catch (\Throwable $e) {
                $result = ['success' => false, 'status' => 'failed', 'metadata' => ['error' => $e->getMessage()]];
            }

            if (! $result['success']) {
                $message = is_string($result['metadata']['error'] ?? null) ? $result['metadata']['error'] : 'Provider refused the cancellation.';

                if (! $force) {
                    throw new StandingOrderException($message);
                }

                $providerPending = true;
                Log::warning('Billing: standing order cancelled locally only', ['standing_order' => $order->ulid, 'error' => $message]);
            }
        }

        $this->markCancelled($order, $reason, $providerPending);

        return $order->refresh();
    }

    /**
     * Whether a paybill C2B confirmation is the debit of one of our standing
     * orders, by the account reference the customer's order was created with.
     * A paybill has one confirmation URL, so ordinary payments and standing
     * order debits arrive together and this tells them apart.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function isDebitOfOrder(array $payload, string $provider = 'mpesa'): bool
    {
        $reference = $payload['BillRefNumber'] ?? null;

        return is_string($payload['TransID'] ?? null)
            && is_string($reference) && $reference !== ''
            && StandingOrder::query()->where('provider', $provider)->open()->where('account_reference', $reference)->exists();
    }

    /**
     * Apply a provider callback. Safe to call repeatedly with the same payload.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{handled: bool, event: string|null, standing_order: StandingOrder|null}
     */
    public function handleCallback(array $payload, string $provider = 'mpesa'): array
    {
        $parsed = $this->provider($provider)->parseStandingOrderCallback($payload);

        $order = $this->locate($parsed, $provider);
        if (! $order instanceof StandingOrder) {
            Log::warning('Billing: standing order callback matched no order', ['provider' => $provider]);

            return ['handled' => false, 'event' => null, 'standing_order' => null];
        }

        $status = strtolower($parsed['status'] ?? '');
        $isDebit = isset($parsed['raw']['TransID']);

        if (str_contains($status, 'cancel')) {
            if ($order->status !== StandingOrderStatus::Cancelled) {
                $this->markCancelled($order, 'cancelled_by_customer', false);
            }

            return ['handled' => true, 'event' => 'cancelled', 'standing_order' => $order->refresh()];
        }

        if ($order->status === StandingOrderStatus::Pending) {
            if (! $parsed['success']) {
                $this->failCreation($order, $parsed['description'] ?? 'The customer did not approve the standing order.', $parsed);

                return ['handled' => true, 'event' => 'failed', 'standing_order' => $order->refresh()];
            }

            $this->activate($order);

            if (! $isDebit) {
                return ['handled' => true, 'event' => 'activated', 'standing_order' => $order->refresh()];
            }
        }

        if ($parsed['transaction_id'] === null && $parsed['success']) {
            return ['handled' => true, 'event' => 'ignored', 'standing_order' => $order];
        }

        $recorded = $this->recordExecution($order, $parsed);

        return ['handled' => true, 'event' => $recorded, 'standing_order' => $order->refresh()];
    }

    /**
     * Status sweep for pending/active orders: expire finished or never-approved
     * orders, pick up provider-side cancellations and flag missed debits.
     *
     * @return array{checked: int, activated: int, cancelled: int, expired: int, missed: int, failed: int}
     */
    public function reconcile(?string $provider = null): array
    {
        $summary = ['checked' => 0, 'activated' => 0, 'cancelled' => 0, 'expired' => 0, 'missed' => 0, 'failed' => 0];

        $query = StandingOrder::query()->whereIn('status', [StandingOrderStatus::Pending->value, StandingOrderStatus::Active->value]);
        if ($provider !== null) {
            $query->where('provider', $provider);
        }

        $timeoutHours = $this->intConfig('pending_timeout_hours', 24);
        $graceDays = $this->intConfig('missed_grace_days', 2);
        $maxFailures = $this->intConfig('max_failures', 3);

        foreach ($query->orderBy('id')->get() as $order) {
            $summary['checked']++;

            if ($order->end_date !== null && $order->end_date->endOfDay()->isPast()) {
                $this->setStatus($order, StandingOrderStatus::Expired, ['expired_reason' => 'end_date_passed']);
                $summary['expired']++;

                continue;
            }

            $remote = $this->remoteStatus($order);

            if ($remote !== null && str_contains($remote, 'cancel')) {
                $this->markCancelled($order, 'cancelled_at_provider', false);
                $summary['cancelled']++;

                continue;
            }

            if ($order->status === StandingOrderStatus::Pending) {
                if ($remote !== null && (str_contains($remote, 'active') || str_contains($remote, 'approved'))) {
                    $this->activate($order);
                    $summary['activated']++;
                } elseif ($order->created_at->lt(now()->subHours($timeoutHours))) {
                    $this->setStatus($order, StandingOrderStatus::Expired, ['expired_reason' => 'approval_timeout']);
                    $summary['expired']++;
                }

                continue;
            }

            if ($order->next_expected_at !== null && $order->next_expected_at->copy()->addDays($graceDays)->isPast()) {
                $this->recordMissed($order, $maxFailures);
                $summary['missed']++;
                $order->refresh();
                if ($order->status === StandingOrderStatus::Failed) {
                    $summary['failed']++;
                }
            }
        }

        return $summary;
    }

    // ─── Internals ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $extraMetadata
     */
    protected function createOrder(Model $billable, ?Subscription $subscription, int $amount, string $currency, string $phone, array $options, array $extraMetadata = []): StandingOrder
    {
        $consent = $this->consent($options);
        $providerName = is_string($options['provider'] ?? null) ? $options['provider'] : 'mpesa';
        $provider = $this->provider($providerName);
        $frequency = $this->frequencyOption($options['frequency'] ?? null) ?? StandingOrderFrequency::Monthly;

        $ulid = Str::ulid()->toBase32();
        $name = is_string($options['name'] ?? null) && $options['name'] !== ''
            ? $options['name']
            : 'Plan '.substr($ulid, -8);
        $accountReference = is_string($options['account_reference'] ?? null) && $options['account_reference'] !== ''
            ? $options['account_reference']
            : strtoupper(substr($ulid, -10));
        $accountReference = substr((string) preg_replace('/[^A-Za-z0-9]/', '', $accountReference), 0, 12);
        $msisdn = $this->normalisePhone($phone);

        $start = $options['start_date'] ?? now()->toDateString();
        $end = $options['end_date'] ?? null;

        $result = $provider->createStandingOrder([
            'name' => $name,
            'amount' => $amount,
            'phone' => $msisdn,
            'frequency' => $frequency,
            'start_date' => $start,
            'end_date' => $end,
            'account_reference' => $accountReference,
            'description' => $options['description'] ?? null,
            'transaction_type' => $options['transaction_type'] ?? null,
            'callback_url' => $options['callback_url'] ?? null,
        ]);

        $order = new StandingOrder([
            'ulid' => $ulid,
            'provider' => $providerName,
            'provider_reference' => $result['provider_standing_order_id'] ?? $result['provider_reference'],
            'account_reference' => $accountReference,
            'name' => $name,
            'amount' => $amount,
            'currency' => $currency,
            'frequency' => $frequency,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $result['success'] ? StandingOrderStatus::Pending : StandingOrderStatus::Failed,
            'consent_at' => $consent['at'],
            'consent_ip' => $consent['ip'],
            'consent_user_agent' => $consent['user_agent'],
            'failure_count' => 0,
            'subscription_id' => $subscription?->id,
            'metadata' => [...$extraMetadata, 'request_reference' => $result['provider_reference'], 'create_response' => $result['metadata']],
        ]);
        $order->setCustomerPhone($msisdn);
        $order->billable()->associate($billable);
        $order->save();

        if (! $result['success']) {
            $error = $result['metadata']['error'] ?? 'The provider rejected the standing order request.';
            StandingOrderFailed::dispatch($order, null, is_string($error) ? $error : 'The provider rejected the standing order request.', 0);
        }

        return $order;
    }

    protected function activate(StandingOrder $order): void
    {
        $start = $order->start_date->copy()->startOfDay();
        $next = $start->isFuture() || $start->isToday() ? $start : ($order->frequency->nextAfter($start) ?? $start);

        $order->forceFill(['status' => StandingOrderStatus::Active, 'next_expected_at' => $next])->save();

        StandingOrderActivated::dispatch($order);
    }

    /**
     * @param  array<array-key, mixed>  $parsed
     */
    protected function failCreation(StandingOrder $order, string $reason, array $parsed): void
    {
        $order->forceFill([
            'status' => StandingOrderStatus::Failed,
            'failure_count' => $order->failure_count + 1,
            'metadata' => [...($order->metadata ?? []), 'failure_reason' => $reason, 'failure_code' => $parsed['result_code'] ?? null],
        ])->save();

        StandingOrderFailed::dispatch($order, null, $reason, $order->failure_count);
    }

    protected function markCancelled(StandingOrder $order, string $reason, bool $providerPending): void
    {
        $order->forceFill([
            'status' => StandingOrderStatus::Cancelled,
            'next_expected_at' => null,
            'metadata' => [
                ...($order->metadata ?? []),
                'cancel_reason' => $reason,
                'cancelled_at' => now()->toIso8601String(),
                ...($providerPending ? ['provider_cancel_pending' => true] : []),
            ],
        ])->save();

        StandingOrderCancelled::dispatch($order, $reason);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function setStatus(StandingOrder $order, StandingOrderStatus $status, array $metadata = []): void
    {
        $order->forceFill([
            'status' => $status,
            'metadata' => [...($order->metadata ?? []), ...$metadata],
        ])->save();
    }

    /**
     * Record a debit result idempotently. Returns the event name applied.
     *
     * @param  array<array-key, mixed>  $parsed
     */
    protected function recordExecution(StandingOrder $order, array $parsed): string
    {
        $transactionId = is_string($parsed['transaction_id'] ?? null) ? $parsed['transaction_id'] : null;
        $success = (bool) ($parsed['success'] ?? false);
        $amount = is_int($parsed['amount'] ?? null) ? $parsed['amount'] : $order->amount;
        $raw = is_array($parsed['raw'] ?? null) ? $parsed['raw'] : [];
        /** @var array<string, mixed> $raw */
        $maxFailures = $this->intConfig('max_failures', 3);

        $execution = null;
        $payment = null;

        try {
            DB::transaction(function () use ($order, $transactionId, $success, $amount, $parsed, $raw, $maxFailures, &$execution, &$payment): void {
                /** @var StandingOrder $locked */
                $locked = StandingOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($transactionId !== null
                    && StandingOrderExecution::query()->where('provider_transaction_id', $transactionId)->exists()) {
                    return;
                }

                $execution = new StandingOrderExecution([
                    'provider_transaction_id' => $transactionId,
                    'amount' => $amount,
                    'status' => $success ? 'completed' : 'failed',
                    'result_code' => $parsed['result_code'] ?? null,
                    'result_description' => is_string($parsed['description'] ?? null) ? substr($parsed['description'], 0, 500) : null,
                    'executed_at' => now(),
                    'raw' => $raw,
                ]);

                $subscription = $locked->subscription;

                $payment = new Payment([
                    'ulid' => Str::ulid()->toBase32(),
                    'subscription_id' => $locked->subscription_id,
                    'amount' => $amount,
                    'currency' => $locked->currency,
                    'status' => $success ? PaymentStatus::COMPLETED : PaymentStatus::FAILED,
                    'payment_provider' => $locked->provider,
                    'payment_method' => PaymentMethod::MPESA,
                    'provider_payment_id' => $success ? $transactionId : null,
                    'provider_reference' => $locked->provider_reference,
                    'paid_at' => $success ? now() : null,
                    'failed_at' => $success ? null : now(),
                    'metadata' => ['standing_order' => $locked->ulid, 'source' => 'standing_order'],
                ]);
                $locked->billable->morphMany(Payment::class, 'billable')->save($payment);

                $execution->standing_order_id = $locked->id;
                $execution->payment_id = $payment->id;
                $execution->save();

                if ($success) {
                    $next = $locked->frequency->nextAfter($locked->next_expected_at ?? now());
                    $locked->forceFill([
                        'last_executed_at' => now(),
                        'next_expected_at' => $next,
                        'failure_count' => 0,
                        'status' => $locked->frequency === StandingOrderFrequency::OneOff ? StandingOrderStatus::Expired : $locked->status,
                    ])->save();

                    if ($subscription instanceof Subscription) {
                        $this->extendSubscription($subscription);
                    }
                } else {
                    $failures = $locked->failure_count + 1;
                    $locked->forceFill([
                        'failure_count' => $failures,
                        'status' => $failures >= $maxFailures ? StandingOrderStatus::Failed : $locked->status,
                    ])->save();

                    if ($subscription instanceof Subscription && $subscription->current_period_end?->isPast()) {
                        $subscription->update(['status' => SubscriptionStatus::PAST_DUE]);
                    }
                }
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate'; // a concurrent delivery of the same transaction won the race
        }

        if (! $execution instanceof StandingOrderExecution || ! $payment instanceof Payment) {
            return 'duplicate';
        }

        $order->refresh();

        if ($success) {
            PaymentReceived::dispatch($payment, $order->billable, $payment->amount, $payment->currency, 'mpesa', $transactionId);
            $subscription = $order->subscription;
            if ($subscription instanceof Subscription && $subscription->current_period_start !== null && $subscription->current_period_end !== null) {
                SubscriptionRenewed::dispatch($subscription, $order->billable, $subscription->plan, $subscription->current_period_start, $subscription->current_period_end, $payment->amount, $payment->currency);
            }
            StandingOrderExecuted::dispatch($order, $execution, $payment);

            return 'executed';
        }

        $reason = $execution->result_description ?? 'Standing order debit failed';
        PaymentFailed::dispatch($payment, $order->billable, $payment->amount, $payment->currency, $reason, $order->failure_count);
        StandingOrderFailed::dispatch($order, $execution, $reason, $order->failure_count);

        return 'failed';
    }

    protected function extendSubscription(Subscription $subscription): void
    {
        $subscription->loadMissing('plan');
        $end = $subscription->current_period_end;
        $from = $end !== null && $end->isFuture() ? $end : now();

        $subscription->update([
            'current_period_start' => $end !== null && $end->isFuture() ? ($subscription->current_period_start ?? now()) : now(),
            'current_period_end' => $from->copy()->addDays($subscription->plan->billing_cycle->days()),
            'status' => in_array($subscription->status, [SubscriptionStatus::PAST_DUE, SubscriptionStatus::EXPIRED], true)
                ? SubscriptionStatus::ACTIVE
                : $subscription->status,
        ]);
    }

    protected function recordMissed(StandingOrder $order, int $maxFailures): void
    {
        $failures = $order->failure_count + 1;
        $expected = $order->next_expected_at ?? now();
        $next = $order->frequency->nextAfter($expected);

        $order->forceFill([
            'failure_count' => $failures,
            'next_expected_at' => $next,
            'status' => ($failures >= $maxFailures || $next === null) ? StandingOrderStatus::Failed : $order->status,
            'metadata' => [...($order->metadata ?? []), 'last_missed_at' => $expected->toIso8601String()],
        ])->save();

        StandingOrderFailed::dispatch($order, null, 'missed_execution', $failures);
    }

    protected function remoteStatus(StandingOrder $order): ?string
    {
        if ($order->provider_reference === null) {
            return null;
        }

        try {
            $result = $this->provider($order->provider)->getStandingOrderStatus($order->provider_reference);
        } catch (\Throwable) {
            return null;
        }

        return $result['success'] ? strtolower($result['status']) : null;
    }

    /**
     * @param  array<array-key, mixed>  $parsed
     */
    protected function locate(array $parsed, string $provider): ?StandingOrder
    {
        $refs = array_values(array_filter([
            $parsed['provider_reference'] ?? null,
            $parsed['request_reference'] ?? null,
            $parsed['standing_order_id'] ?? null,
        ], fn ($v): bool => is_string($v) && $v !== ''));

        if ($refs !== []) {
            $order = StandingOrder::query()->where('provider', $provider)->whereIn('provider_reference', $refs)->first();
            if ($order instanceof StandingOrder) {
                return $order;
            }

            // The request reference is kept in metadata when the create call returned another id.
            foreach (StandingOrder::query()->where('provider', $provider)->open()->get() as $candidate) {
                $request = $candidate->metadata['request_reference'] ?? null;
                if (is_string($request) && in_array($request, $refs, true)) {
                    return $candidate;
                }
            }
        }

        $account = $parsed['account_reference'] ?? null;
        if (is_string($account) && $account !== '') {
            return StandingOrder::query()->where('provider', $provider)->where('account_reference', $account)->latest('id')->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{at: CarbonInterface, ip: string|null, user_agent: string|null}
     */
    protected function consent(array $options): array
    {
        $consent = $options['consent'] ?? null;

        if ($consent !== true && ! is_array($consent)) {
            throw new StandingOrderException('Explicit customer consent is required to create a standing order.');
        }

        $data = is_array($consent) ? $consent : [];
        $at = $data['at'] ?? null;
        $ip = $data['ip'] ?? ($options['consent_ip'] ?? null);
        $agent = $data['user_agent'] ?? ($options['consent_user_agent'] ?? null);

        return [
            'at' => $at instanceof CarbonInterface ? $at : (is_string($at) ? Carbon::parse($at) : now()),
            'ip' => is_string($ip) ? substr($ip, 0, 64) : null,
            'user_agent' => is_string($agent) ? substr($agent, 0, 512) : null,
        ];
    }

    protected function frequencyOption(mixed $value): ?StandingOrderFrequency
    {
        if ($value instanceof StandingOrderFrequency) {
            return $value;
        }

        return is_string($value) ? StandingOrderFrequency::tryFrom($value) : null;
    }

    protected function defaultAmount(Subscription $subscription): int
    {
        $override = $subscription->metadata['amount'] ?? null;

        if (is_numeric($override) && (int) $override == $override) {
            return (int) $override;
        }

        return $subscription->plan->base_price;
    }

    protected function normalisePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0')) {
            return '254'.substr($digits, 1);
        }

        return strlen($digits) === 9 ? '254'.$digits : $digits;
    }

    protected function intConfig(string $key, int $default): int
    {
        $value = config("billing.providers.mpesa.ratiba.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}

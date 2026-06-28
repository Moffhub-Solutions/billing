<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\BillableInterface;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Exceptions\TransactionLimitException;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\PaymentManager;

/**
 * Orchestrates split payments: when a charge exceeds a provider's per-transaction
 * limit, it is broken into a group of tranche payments that settle one invoice.
 *
 * The library does not impose a collection style. It always initiates the first
 * tranche; the remaining tranches are then either advanced automatically as each
 * one settles (when `billing.split_payments.auto_advance` is true) or collected
 * on demand by the consuming app via `collectTranche()`.
 */
class SplitPaymentService
{
    public function __construct(
        protected PaymentManager $paymentManager,
        protected PaymentSplitter $splitter,
    ) {}

    /**
     * Plan, persist, and begin collecting a (possibly split) payment.
     *
     * @param  array<string, mixed>  $options  Provider options (phone, reference, etc.)
     * @param  array<string, mixed>  $attributes  Extra Payment attributes (invoice_id, subscription_id, payment_method)
     * @return array{split: bool, group: string|null, tranche_count: int, payments: array<int, Payment>, success: bool}
     *
     * @throws TransactionLimitException
     */
    public function process(
        Model&BillableInterface $billable,
        string $provider,
        int $amount,
        string $currency,
        array $options = [],
        array $attributes = [],
    ): array {
        $limits = $this->paymentManager->getProviderLimits($provider);
        $tranches = $this->splitter->split($amount, $limits, $provider, $this->usedToday($billable, $provider));

        $isSplit = count($tranches) > 1;
        $group = $isSplit ? (string) Str::ulid() : null;

        /** @var array<int, Payment> $payments */
        $payments = [];

        foreach (array_values($tranches) as $index => $trancheAmount) {
            $payment = new Payment(array_merge($attributes, [
                'ulid' => Str::ulid()->toBase32(),
                'amount' => $trancheAmount,
                'currency' => $currency,
                'status' => PaymentStatus::PENDING,
                'payment_provider' => $provider,
                'payment_group' => $group,
                'group_sequence' => $isSplit ? $index + 1 : null,
                'group_size' => $isSplit ? count($tranches) : null,
            ]));

            $billable->payments()->save($payment);
            $payments[] = $payment;
        }

        // Always initiate the first tranche. For synchronous providers (e.g.
        // manual) that settle immediately, advance through the chain now.
        $first = $payments[0];
        $result = $this->collectTranche($first, $billable, $options);

        // If the first tranche cannot be initiated, fail the whole group so no
        // orphan pending tranches remain collectible or auto-advanceable. The
        // outcome is deterministic: either the group is established and started,
        // or nothing is left in a chargeable state.
        if (! $result['success']) {
            $this->failGroup($group);

            return [
                'split' => $isSplit,
                'group' => $group,
                'tranche_count' => count($tranches),
                'payments' => array_map(fn (Payment $p): Payment => $p->refresh(), $payments),
                'success' => false,
            ];
        }

        $this->advanceSynchronously($first->refresh(), $billable, $options);

        return [
            'split' => $isSplit,
            'group' => $group,
            'tranche_count' => count($tranches),
            'payments' => array_map(fn (Payment $p): Payment => $p->refresh(), $payments),
            'success' => $result['success'],
        ];
    }

    /**
     * Mark every still-pending tranche of a group as failed. Used when the
     * group's first tranche could not be initiated, so the group never lingers
     * in a half-started state. No-op for unsplit (groupless) payments.
     */
    protected function failGroup(?string $group): void
    {
        if ($group === null) {
            return;
        }

        Payment::query()
            ->where('payment_group', $group)
            ->where('status', PaymentStatus::PENDING->value)
            ->update([
                'status' => PaymentStatus::FAILED->value,
                'failed_at' => now(),
            ]);
    }

    /**
     * Initiate a single (pending) tranche with its provider.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, status: string}
     */
    public function collectTranche(Payment $payment, Model&BillableInterface $billable, array $options = []): array
    {
        $provider = $payment->payment_provider ?? 'manual';
        $driver = $this->paymentManager->driver($provider);

        if (! $driver instanceof PaymentProviderInterface) {
            $payment->forceFill(['status' => PaymentStatus::FAILED, 'failed_at' => now()])->save();

            return ['success' => false, 'status' => 'failed'];
        }

        // Give each tranche a distinct reference so providers don't collide.
        $trancheOptions = $options;
        if (! isset($trancheOptions['reference'])) {
            $trancheOptions['reference'] = $payment->ulid;
        }

        $result = $driver->charge($payment->amount, $payment->currency, $trancheOptions);

        $status = $result['success']
            ? $this->mapStatus($result['status'])
            : PaymentStatus::FAILED;

        $payment->forceFill([
            'status' => $status,
            'provider_payment_id' => $result['provider_payment_id'] ?? $payment->provider_payment_id,
            'provider_reference' => $result['provider_reference'] ?? $payment->provider_reference,
            'metadata' => array_merge($payment->metadata ?? [], $result['metadata']),
            'paid_at' => $status === PaymentStatus::COMPLETED ? now() : $payment->paid_at,
            'failed_at' => $status === PaymentStatus::FAILED ? now() : null,
        ])->save();

        if ($status === PaymentStatus::COMPLETED) {
            $this->onTrancheCompleted($payment, $billable);
        }

        return ['success' => (bool) $result['success'], 'status' => $status->value];
    }

    /**
     * Advance to the next pending tranche after one settles (e.g. from a
     * webhook). Returns the tranche that was initiated, or null when the group
     * is finished or auto-advance is disabled.
     *
     * @param  array<string, mixed>  $options
     */
    public function advance(Payment $justCompleted, Model&BillableInterface $billable, array $options = []): ?Payment
    {
        if (! $this->autoAdvanceEnabled() || $justCompleted->payment_group === null) {
            return null;
        }

        $next = $justCompleted->nextPendingTranche();

        if ($next === null) {
            return null;
        }

        $this->collectTranche($next, $billable, $options);

        return $next->refresh();
    }

    /**
     * Count provider transactions already consumed today for the daily cap.
     *
     * Counts what has actually reached the provider: completed payments, plus
     * pending payments that were genuinely initiated (have a provider payment
     * id). Pending tranches still awaiting collection (never charged) and failed
     * payments do not count, so an abandoned split group can't block the
     * billable's remaining daily allowance.
     */
    public function usedToday(Model&BillableInterface $billable, string $provider): int
    {
        return $billable->payments()
            ->where('payment_provider', $provider)
            ->where('created_at', '>=', Carbon::today())
            ->where(function ($query): void {
                $query->where('status', PaymentStatus::COMPLETED->value)
                    ->orWhere(function ($pending): void {
                        $pending->where('status', PaymentStatus::PENDING->value)
                            ->whereNotNull('provider_payment_id');
                    });
            })
            ->count();
    }

    /**
     * Chain forward through tranches that settle synchronously in-process.
     *
     * @param  array<string, mixed>  $options
     */
    protected function advanceSynchronously(Payment $payment, Model&BillableInterface $billable, array $options): void
    {
        if (! $this->autoAdvanceEnabled()) {
            return;
        }

        $guard = 0;
        while ($payment->isCompleted() && $guard < 100) {
            $guard++;
            $next = $payment->nextPendingTranche();

            if ($next === null) {
                return;
            }

            $this->collectTranche($next, $billable, $options);
            $payment = $next->refresh();
        }
    }

    /**
     * Fire the completion event and keep the linked invoice's status in sync.
     *
     * @param  Model&BillableInterface  $billable
     */
    protected function onTrancheCompleted(Payment $payment, Model $billable): void
    {
        PaymentReceived::dispatch(
            $payment,
            $billable,
            $payment->amount,
            $payment->currency,
            $payment->payment_method?->value,
            $payment->provider_reference,
        );

        $this->syncInvoice($payment);
    }

    protected function syncInvoice(Payment $payment): void
    {
        if ($payment->invoice_id === null) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            $invoice = Invoice::query()->lockForUpdate()->find($payment->invoice_id);
            $invoice?->recalculateStatus();
        });
    }

    protected function autoAdvanceEnabled(): bool
    {
        return (bool) config('billing.split_payments.auto_advance', true);
    }

    protected function mapStatus(string $status): PaymentStatus
    {
        return match (strtolower($status)) {
            'completed', 'success', 'successful', 'paid' => PaymentStatus::COMPLETED,
            'failed', 'cancelled', 'canceled' => PaymentStatus::FAILED,
            default => PaymentStatus::PENDING,
        };
    }
}

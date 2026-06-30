<?php

declare(strict_types=1);

namespace Moffhub\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\WebhookSettlement;

/**
 * Confirms an unverified webhook by re-querying the provider's own status API,
 * then settling only on a terminal result. A forged or replayed callback that
 * isn't backed by a real transaction simply never confirms, so nothing settles.
 *
 * Runs off the request (queued), so the inbound webhook is never blocked on the
 * outbound provider call. ShouldBeUnique dedupes a flood of callbacks for the
 * same provider payment id into a single in-flight job.
 */
class ConfirmWebhookPayment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Initial attempt plus the retries implied by the backoff schedule. */
    public int $tries = 4;

    public function __construct(
        public readonly string $provider,
        public readonly string $providerPaymentId,
    ) {}

    public function uniqueId(): string
    {
        return $this->provider.':'.$this->providerPaymentId;
    }

    public function handle(PaymentManager $manager, WebhookSettlement $settlement): void
    {
        // If another delivery already settled it, stop.
        if (! $settlement->hasPendingPayment($this->providerPaymentId)) {
            return;
        }

        $driver = $manager->driver($this->provider);

        if (! $driver instanceof PaymentProviderInterface) {
            Log::error("ConfirmWebhookPayment: driver for {$this->provider} is not a PaymentProviderInterface");

            return;
        }

        $status = $driver->getPaymentStatus($this->providerPaymentId);

        // Terminal status from the provider's authenticated API -> settle.
        if ($settlement->mapStatus($status) !== null) {
            $settlement->settle($this->provider, $this->providerPaymentId, $status, [
                'confirmed_via' => 'requery',
            ]);

            return;
        }

        // Still pending: the callback may have arrived before the provider's
        // status API caught up. Retry on the configured backoff, then give up
        // and leave it for the next callback or the reconciliation cron.
        $backoff = $this->backoff();
        $attempt = $this->attempts();

        if ($attempt >= count($backoff) + 1) {
            Log::info("ConfirmWebhookPayment: gave up confirming {$this->provider} payment after {$attempt} attempts", [
                'provider_payment_id' => $this->providerPaymentId,
            ]);

            return;
        }

        $this->release($backoff[$attempt - 1] ?? 600);
    }

    /**
     * Re-query backoff schedule (seconds), from config.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $raw = config('billing.webhooks.confirm_backoff', [30, 120, 600]);

        if (! is_array($raw)) {
            return [30, 120, 600];
        }

        $delays = [];
        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $delays[] = (int) $value;
            }
        }

        return $delays === [] ? [30, 120, 600] : $delays;
    }
}

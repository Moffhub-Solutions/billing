<?php

declare(strict_types=1);

namespace Moffhub\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\ReconciliationDrift;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\PayOrchestraProvider;

/**
 * Nightly reconciliation: walk the last N hours of PayOrchestra-routed payments,
 * cross-check each against backbone's view of the intent, and record drifts.
 *
 * v1.0 lite scope: report-and-log only. v1.1 adds an in-product UI with auto-resolve
 * patterns (lost-webhook → resync, status-mismatch → re-verify).
 */
class ReconcileWithPayOrchestraCommand extends Command
{
    protected $signature = 'billing:reconcile-payorchestra
        {--hours=24 : Look back this many hours for payments to reconcile}
        {--dry-run : Detect drifts but do not persist them}
        {--limit=500 : Cap the number of payments inspected per run}';

    protected $description = 'Cross-check billing payments against PayOrchestra backbone and log drifts';

    public function handle(PaymentManager $paymentManager): int
    {
        $driver = $paymentManager->driver('payorchestra');

        if (! $driver instanceof PayOrchestraProvider) {
            $this->error('PayOrchestra driver is not configured. Aborting.');

            return self::FAILURE;
        }

        if (! $driver->isConfigured()) {
            $this->error('PayOrchestra driver is missing required env (URL/API_KEY/ORG_ID). Aborting.');

            return self::FAILURE;
        }

        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $since = Carbon::now()->subHours($hours);

        $payments = Payment::query()
            ->where('payment_provider', 'payorchestra')
            ->whereNotNull('provider_payment_id')
            ->where('updated_at', '>=', $since)
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();

        $this->line(sprintf(
            'Reconciling %d payments updated in the last %d hour(s)%s.',
            $payments->count(),
            $hours,
            $dryRun ? ' (dry-run)' : '',
        ));

        $checked = 0;
        $drifts = 0;
        $errors = 0;

        foreach ($payments as $payment) {
            $checked++;

            $providerStatus = null;

            try {
                $providerStatus = $driver->getPaymentStatus($payment->provider_payment_id);
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("[{$payment->ulid}] backbone query failed: {$e->getMessage()}");

                continue;
            }

            if ($providerStatus === 'unknown') {
                $errors++;
                $this->warn("[{$payment->ulid}] backbone returned 'unknown' (likely 4xx/5xx)");

                continue;
            }

            $billingStatus = $payment->status instanceof PaymentStatus
                ? $payment->status->value
                : (string) $payment->status;

            if ($this->statusesAgree($billingStatus, $providerStatus)) {
                continue;
            }

            $drifts++;
            $this->warn(sprintf(
                '[%s] DRIFT — billing=%s, payorchestra=%s',
                $payment->ulid,
                $billingStatus,
                $providerStatus,
            ));

            if ($dryRun) {
                continue;
            }

            ReconciliationDrift::query()->updateOrCreate(
                [
                    'payment_id' => $payment->id,
                    'resolved_at' => null,
                ],
                [
                    'provider' => 'payorchestra',
                    'provider_payment_id' => $payment->provider_payment_id,
                    'billing_status' => $billingStatus,
                    'provider_status' => $providerStatus,
                    'details' => [
                        'billable_type' => $payment->billable_type,
                        'billable_id' => $payment->billable_id,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'detected_at' => Carbon::now()->toIso8601String(),
                    ],
                    'detected_at' => Carbon::now(),
                ],
            );
        }

        $this->line(str_repeat('─', 50));
        $this->info(sprintf(
            'Done. checked=%d drifts=%d errors=%d',
            $checked,
            $drifts,
            $errors,
        ));

        // Non-zero exit if any drift was found, so cron can alert.
        return $drifts > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Compare billing.payments.status (PaymentStatus enum) against the normalized
     * status returned by PayOrchestraProvider::getPaymentStatus(). Both sides have
     * already been mapped through provider-specific aliases, so we just need to
     * agree on the four terminal states.
     */
    private function statusesAgree(string $billingStatus, string $providerStatus): bool
    {
        $billing = strtolower($billingStatus);
        $provider = strtolower($providerStatus);

        if ($billing === $provider) {
            return true;
        }

        // Treat pending/processing on the provider side as compatible with billing's
        // pending — both mean "not terminal yet".
        $nonTerminal = ['pending', 'processing'];

        return in_array($billing, $nonTerminal, true) && in_array($provider, $nonTerminal, true);
    }
}

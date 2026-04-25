<?php

declare(strict_types=1);

namespace Moffhub\Billing\Console\Commands;

use Illuminate\Console\Command;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\PayOrchestraProvider;

/**
 * Deploy-day smoke test for the PayOrchestra integration.
 *
 * Drives a small payment intent end-to-end through the configured backbone and
 * verifies each hop returns a sane shape. Intended to run against staging or
 * immediately post-deploy in production with a known small amount.
 *
 * Steps:
 *   1. Driver is configured (URL, API key, org id, webhook secret all present)
 *   2. Backbone responds to the connectors-installed listing
 *   3. POST /payment-intents accepts our payload and returns a redirect URL
 *   4. GET /payment-intents/{id} returns the intent we just created
 *
 * Does NOT trigger a real charge — the flow stops at intent creation. To test
 * the full webhook → activation chain, run a sandbox Paystack charge from the
 * checkout URL printed below and watch billing's Payment record update.
 */
class PayOrchestraSmokeTestCommand extends Command
{
    protected $signature = 'billing:smoke-payorchestra
        {--amount=100 : Test amount in minor units (default: 1.00)}
        {--currency=KES : Test currency (default: KES)}
        {--callback-url= : Override the merchant webhook URL for this test}';

    protected $description = 'Deploy-day smoke test against the configured PayOrchestra backbone';

    public function handle(PaymentManager $paymentManager): int
    {
        $driver = $paymentManager->driver('payorchestra');

        if (! $driver instanceof PayOrchestraProvider) {
            $this->error('PayOrchestra driver is not configured.');

            return self::FAILURE;
        }

        $this->line(str_repeat('─', 60));
        $this->line('PayOrchestra deploy-day smoke test');
        $this->line(str_repeat('─', 60));

        // Step 1 — config is complete
        if (! $this->stepConfigured($driver)) {
            return self::FAILURE;
        }

        // Step 2 — backbone is reachable, returns the connectors list
        if (! $this->stepConnectors($driver)) {
            return self::FAILURE;
        }

        // Step 3 — create an intent
        $intent = $this->stepCreateIntent($driver);
        if ($intent === null) {
            return self::FAILURE;
        }

        $providerPaymentIdRaw = $intent['provider_payment_id'] ?? null;
        $providerPaymentId = is_string($providerPaymentIdRaw) ? $providerPaymentIdRaw : '';

        // Step 4 — read the intent back
        if (! $this->stepReadIntent($driver, $providerPaymentId)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Smoke test passed.');
        $this->line("Provider payment id: {$providerPaymentId}");

        $metadataRaw = $intent['metadata'] ?? null;
        if (is_array($metadataRaw)) {
            $checkoutUrlRaw = $metadataRaw['checkout_url'] ?? null;
            if (is_string($checkoutUrlRaw) && $checkoutUrlRaw !== '') {
                $this->line('Checkout URL (drive a sandbox charge here to exercise the webhook chain):');
                $this->line('  '.$checkoutUrlRaw);
            }
        }

        return self::SUCCESS;
    }

    private function stepConfigured(PayOrchestraProvider $driver): bool
    {
        if ($driver->isConfigured()) {
            $this->info('[1/4] Driver configured');

            return true;
        }

        $this->error('[1/4] Driver missing required env (PAYORCHESTRA_URL, PAYORCHESTRA_API_KEY, PAYORCHESTRA_ORG_ID).');

        return false;
    }

    private function stepConnectors(PayOrchestraProvider $driver): bool
    {
        try {
            $channels = $driver->availableChannels();
        } catch (\Throwable $e) {
            $this->error('[2/4] Backbone unreachable: '.$e->getMessage());

            return false;
        }

        if (count($channels) === 0) {
            $this->warn('[2/4] Backbone responded but reports zero installed connectors. Continuing — but the next step will fail.');

            return true;
        }

        $names = collect($channels)->pluck('slug')->implode(', ');
        $this->info("[2/4] Backbone reports {$this->plural(count($channels), 'connector')}: {$names}");

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stepCreateIntent(PayOrchestraProvider $driver): ?array
    {
        $amountRaw = $this->option('amount');
        $amount = max(1, is_numeric($amountRaw) ? (int) $amountRaw : 1);
        $currencyRaw = $this->option('currency');
        $currency = is_string($currencyRaw) ? $currencyRaw : 'KES';
        $callbackUrl = $this->option('callback-url');

        $options = [
            'reference' => 'SMOKE-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)),
            'metadata' => ['source' => 'smoke_test'],
        ];
        if (is_string($callbackUrl) && $callbackUrl !== '') {
            $options['callback_url'] = $callbackUrl;
        }

        try {
            $result = $driver->charge($amount, $currency, $options);
        } catch (\Throwable $e) {
            $this->error('[3/4] charge() threw: '.$e->getMessage());

            return null;
        }

        if (! $result['success']) {
            $errorRaw = $result['metadata']['error'] ?? 'unknown';
            $error = is_string($errorRaw) ? $errorRaw : 'unknown';
            $httpRaw = $result['metadata']['http_status'] ?? '—';
            $http = (is_string($httpRaw) || is_int($httpRaw)) ? (string) $httpRaw : '—';
            $this->error("[3/4] charge() failed (HTTP {$http}): {$error}");

            return null;
        }

        if (empty($result['provider_payment_id'])) {
            $this->error('[3/4] charge() succeeded but returned no provider_payment_id.');

            return null;
        }

        $this->info("[3/4] Intent created: {$result['provider_payment_id']} (status={$result['status']})");

        return $result;
    }

    private function stepReadIntent(PayOrchestraProvider $driver, string $intentId): bool
    {
        try {
            $status = $driver->getPaymentStatus($intentId);
        } catch (\Throwable $e) {
            $this->error('[4/4] getPaymentStatus() threw: '.$e->getMessage());

            return false;
        }

        if ($status === 'unknown') {
            $this->error('[4/4] Backbone returned unknown status for the intent we just created.');

            return false;
        }

        $this->info("[4/4] Intent readback: status={$status}");

        return true;
    }

    private function plural(int $count, string $singular): string
    {
        return $count === 1 ? "1 {$singular}" : "{$count} {$singular}s";
    }
}

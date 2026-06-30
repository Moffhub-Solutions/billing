<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Jobs\ConfirmWebhookPayment;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\WebhookSettlement;
use Symfony\Component\HttpFoundation\IpUtils;

class WebhookController extends Controller
{
    public function __construct(
        protected PaymentManager $paymentManager,
        protected WebhookSettlement $settlement,
    ) {}

    /**
     * Handle M-Pesa callback.
     */
    public function mpesa(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'mpesa');
    }

    /**
     * Handle Paystack webhook.
     */
    public function paystack(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'paystack');
    }

    /**
     * Handle Flutterwave webhook.
     */
    public function flutterwave(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'flutterwave');
    }

    /**
     * Handle Pesapal IPN.
     */
    public function pesapal(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'pesapal');
    }

    /**
     * Handle Airtel Money callback.
     */
    public function airtel(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'airtel');
    }

    /**
     * Handle T-Kash callback.
     */
    public function tkash(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'tkash');
    }

    /**
     * Handle KCB BUNI IPN.
     */
    public function kcb(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'kcb');
    }

    /**
     * Handle Equity Jenga webhook.
     */
    public function jenga(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'jenga');
    }

    /**
     * Handle Co-operative Bank callback.
     */
    public function coopbank(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'coopbank');
    }

    /**
     * Handle Stanbic Bank webhook.
     */
    public function stanbic(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'stanbic');
    }

    /**
     * Handle NCBA IPN.
     */
    public function ncba(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'ncba');
    }

    /**
     * Handle IntaSend webhook.
     */
    public function intasend(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'intasend');
    }

    /**
     * Handle PayOrchestra backbone webhook.
     */
    public function payorchestra(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'payorchestra');
    }

    /**
     * Process a webhook from any provider.
     *
     * A verified callback (valid provider signature, or a matching configured
     * secret) settles inline. An unverified callback is never trusted to move
     * money: if it names a pending payment, an async job re-queries the
     * provider's own status API and settles only on a confirmed result, so a
     * forged or replayed callback settles nothing.
     */
    protected function handleWebhook(Request $request, string $providerName): JsonResponse
    {
        try {
            $driver = $this->paymentManager->driver($providerName);

            if (! $driver instanceof PaymentProviderInterface) {
                Log::error("Billing webhook: driver for {$providerName} is not a PaymentProviderInterface");

                return response()->json(['error' => 'Invalid driver'], 500);
            }

            // Edge gate: reject anything outside a configured provider IP allowlist.
            if (! $this->ipAllowed($request, $providerName)) {
                Log::warning("Billing webhook: rejected IP for {$providerName}", ['ip' => $request->ip()]);

                return response()->json(['error' => 'Forbidden'], 403);
            }

            $event = $driver->parseWebhook($request);

            Log::info("Billing webhook received: {$providerName}/{$event['event']}", [
                'provider' => $providerName,
                'event' => $event['event'],
                'provider_payment_id' => $event['provider_payment_id'],
            ]);

            $verified = $driver->verifyWebhook($request) || $this->secretMatches($request, $providerName);

            if ($verified) {
                $this->settlement->settle($providerName, $event['provider_payment_id'], $event['status'], $event['metadata']);

                return response()->json(['status' => 'received']);
            }

            // Unverified: do not trust the payload to settle.
            if (! $this->confirmUnverified()) {
                Log::warning("Billing webhook: unverified {$providerName} callback rejected (confirm_unverified off, no signature/secret)", [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => 'Unverified'], 403);
            }

            $providerPaymentId = $event['provider_payment_id'];

            // Only enqueue a re-query for a known pending payment, so a flood of
            // forged callbacks for unknown ids can't spawn jobs (ShouldBeUnique
            // dedupes repeats for the same id).
            if (is_string($providerPaymentId) && $providerPaymentId !== '' && $this->settlement->hasPendingPayment($providerPaymentId)) {
                ConfirmWebhookPayment::dispatch($providerName, $providerPaymentId);
            }

            return response()->json(['status' => 'accepted'], 202);
        } catch (\Throwable $e) {
            Log::error("Billing webhook error: {$providerName}", [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Whether the request IP is allowed for this provider. An empty/unset
     * allowlist means "no IP restriction"; when set, only listed IPs/CIDRs pass.
     */
    protected function ipAllowed(Request $request, string $providerName): bool
    {
        $configured = config("billing.webhooks.providers.{$providerName}.ip_allowlist", []);
        $allowlist = is_array($configured)
            ? array_values(array_filter($configured, fn ($v): bool => is_string($v) && $v !== ''))
            : [];

        if ($allowlist === []) {
            return true;
        }

        $ip = $request->ip();

        return $ip !== null && IpUtils::checkIp($ip, $allowlist);
    }

    /**
     * Whether the request carries the provider's configured shared secret,
     * supplied as `?secret=` on the registered URL or an `X-Webhook-Secret`
     * header. Returns false when no secret is configured for the provider.
     */
    protected function secretMatches(Request $request, string $providerName): bool
    {
        $secret = config("billing.webhooks.providers.{$providerName}.secret");

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $headerSecret = (string) $request->header('X-Webhook-Secret', '');
        $queryRaw = $request->query('secret');
        $provided = $headerSecret !== '' ? $headerSecret : (is_string($queryRaw) ? $queryRaw : '');

        return $provided !== '' && hash_equals($secret, $provided);
    }

    protected function confirmUnverified(): bool
    {
        return (bool) config('billing.webhooks.confirm_unverified', true);
    }
}

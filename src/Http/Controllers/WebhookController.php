<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Moffhub\Billing\PaymentManager;

class WebhookController extends Controller
{
    public function __construct(
        protected PaymentManager $paymentManager,
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
     * Process a webhook from any provider.
     */
    protected function handleWebhook(Request $request, string $providerName): JsonResponse
    {
        try {
            $driver = $this->paymentManager->driver($providerName);

            // Verify signature
            if (! $driver->verifyWebhook($request)) {
                Log::warning("Billing webhook: Invalid signature from {$providerName}", [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => 'Invalid signature'], 403);
            }

            // Parse the webhook payload
            $event = $driver->parseWebhook($request);

            Log::info("Billing webhook received: {$providerName}/{$event['event']}", [
                'provider' => $providerName,
                'event' => $event['event'],
                'provider_payment_id' => $event['provider_payment_id'],
            ]);

            // TODO: Process the event — update payment status, fire events, etc.
            // This will be fully implemented in Phase 4 (Subscription Billing Engine)

            return response()->json(['status' => 'received']);
        } catch (\Throwable $e) {
            Log::error("Billing webhook error: {$providerName}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }
}

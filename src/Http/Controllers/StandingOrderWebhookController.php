<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Moffhub\Billing\Services\StandingOrderService;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Receives M-Pesa Ratiba callbacks. Safaricom does not sign them, so a request
 * is accepted only with the shared secret (?secret= or X-Webhook-Secret) or from
 * an allowlisted IP. With neither configured every callback is refused.
 */
class StandingOrderWebhookController extends Controller
{
    public function __construct(protected StandingOrderService $orders) {}

    public function mpesa(Request $request): JsonResponse
    {
        if (! config('billing.providers.mpesa.ratiba.enabled', false)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (! $this->authorised($request)) {
            Log::warning('Billing: unauthorised Ratiba callback rejected', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            $result = $this->orders->handleCallback($request->all(), 'mpesa');
            Log::info('Billing: Ratiba callback processed', ['event' => $result['event']]);

            // Always acknowledge a well-formed, authorised callback so Safaricom does not retry forever.
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        } catch (\Throwable $e) {
            Log::error('Billing: Ratiba callback failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    protected function authorised(Request $request): bool
    {
        $secret = config('billing.providers.mpesa.ratiba.callback_secret');
        if (is_string($secret) && $secret !== '') {
            $header = (string) $request->header('X-Webhook-Secret', '');
            $query = $request->query('secret');
            $provided = $header !== '' ? $header : (is_string($query) ? $query : '');
            if ($provided !== '' && hash_equals($secret, $provided)) {
                return true;
            }
        }

        $configured = config('billing.providers.mpesa.ratiba.ip_allowlist', []);
        $allowlist = is_array($configured)
            ? array_values(array_filter($configured, fn ($v): bool => is_string($v) && $v !== ''))
            : [];
        $ip = $request->ip();

        return $allowlist !== [] && $ip !== null && IpUtils::checkIp($ip, $allowlist);
    }
}

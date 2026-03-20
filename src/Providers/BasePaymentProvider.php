<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Moffhub\Billing\Contracts\PaymentProviderInterface;

abstract class BasePaymentProvider implements PaymentProviderInterface
{
    public function charge(int $amount, string $currency, array $options = []): array
    {
        throw new \BadMethodCallException(static::class.' must implement charge().');
    }

    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        throw new \BadMethodCallException(static::class.' must implement refund().');
    }

    public function getPaymentStatus(string $providerPaymentId): string
    {
        return 'unknown';
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): array
    {
        return [
            'event' => 'unknown',
            'provider_payment_id' => null,
            'status' => 'unknown',
            'amount' => null,
            'currency' => null,
            'metadata' => [],
        ];
    }

    public function isConfigured(): bool
    {
        return false;
    }

    /**
     * Log a provider request (with credential scrubbing).
     */
    protected function logRequest(string $method, string $url, array $payload = []): void
    {
        $scrubbed = $this->scrubSensitiveData($payload);

        Log::channel(config('billing.log_channel', 'stack'))->debug("Billing [{$this->getName()}] {$method} {$url}", [
            'provider' => $this->getName(),
            'payload' => $scrubbed,
        ]);
    }

    /**
     * Recursively scrub sensitive keys from data.
     */
    protected function scrubSensitiveData(array $data): array
    {
        $scrubKeys = ['password', 'secret', 'token', 'api_key', 'consumer_secret', 'auth_token', 'passkey'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->scrubSensitiveData($value);
            } elseif (in_array(strtolower((string) $key), $scrubKeys, true)) {
                $data[$key] = '***REDACTED***';
            }
        }

        return $data;
    }
}

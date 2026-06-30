<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Client\Response;
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
     *
     * @param  array<string, mixed>  $payload
     */
    protected function logRequest(string $method, string $url, array $payload = []): void
    {
        $scrubbed = $this->scrubSensitiveData($payload);

        $channel = config('billing.log_channel', 'stack');

        Log::channel(is_string($channel) ? $channel : 'stack')->debug("Billing [{$this->getName()}] {$method} {$url}", [
            'provider' => $this->getName(),
            'payload' => $scrubbed,
        ]);
    }

    /**
     * Recursively scrub sensitive keys from data.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function scrubSensitiveData(array $data): array
    {
        // Exact-match keys: the operator-tunable list from config, plus PII/short
        // keys that are unsafe to match by substring.
        $configured = config('billing.security.scrub_keys', []);
        $exact = is_array($configured)
            ? array_values(array_filter(array_map(
                fn ($k): ?string => is_string($k) ? strtolower($k) : null,
                $configured,
            )))
            : [];
        $exact = array_merge($exact, [
            'pin', 'cvv', 'card_number', 'phone', 'phonenumber', 'partya', 'msisdn', 'email', 'password',
        ]);

        // Substring matches: any key containing one of these is a secret/PII.
        $substrings = [
            'secret', 'password', 'token', 'passkey', 'api_key', 'apikey',
            'client_id', 'client_secret', 'consumer_key', 'consumer_secret',
            'private_key', 'encryption_key', 'public_key', 'access_token',
            'authorization', 'card_number',
        ];

        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);

            if (is_array($value)) {
                $data[$key] = $this->scrubSensitiveData($value);

                continue;
            }

            if (in_array($lower, $exact, true) || $this->keyMatchesAny($lower, $substrings)) {
                $data[$key] = '***REDACTED***';
            }
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function keyMatchesAny(string $key, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a string option from the options array, or return $default.
     *
     * @param  array<string, mixed>  $options
     */
    protected function optionString(array $options, string $key, string $default = ''): string
    {
        $value = $options[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Read a nullable string option from the options array.
     *
     * @param  array<string, mixed>  $options
     */
    protected function optionNullableString(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read an array option from the options array.
     *
     * @param  array<string, mixed>  $options
     * @return array<array-key, mixed>
     */
    protected function optionArray(array $options, string $key): array
    {
        $value = $options[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Read an int option from the options array.
     *
     * @param  array<string, mixed>  $options
     */
    protected function optionInt(array $options, string $key, int $default = 0): int
    {
        $value = $options[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Convert mixed to a string or null (no coercion of non-strings).
     */
    protected function asNullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Convert mixed to a string with a default.
     */
    protected function asString(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Convert mixed to an int or null.
     */
    protected function asNullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Convert mixed to an array.
     *
     * @return array<array-key, mixed>
     */
    protected function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Read a string value at a dot path on a JSON response.
     */
    protected function jsonString(Response $response, string $path, string $default = ''): string
    {
        $value = $response->json($path);

        return is_string($value) ? $value : $default;
    }

    /**
     * Read a nullable string value at a dot path on a JSON response.
     */
    protected function jsonNullableString(Response $response, string $path): ?string
    {
        $value = $response->json($path);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read an int value at a dot path on a JSON response.
     */
    protected function jsonInt(Response $response, string $path, int $default = 0): int
    {
        $value = $response->json($path);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read a nullable int value at a dot path on a JSON response.
     */
    protected function jsonNullableInt(Response $response, string $path): ?int
    {
        $value = $response->json($path);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Read an array value at a dot path on a JSON response.
     *
     * @return array<array-key, mixed>
     */
    protected function jsonArray(Response $response, string $path): array
    {
        $value = $response->json($path);

        return is_array($value) ? $value : [];
    }
}

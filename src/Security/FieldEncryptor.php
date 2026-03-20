<?php

declare(strict_types=1);

namespace Moffhub\Billing\Security;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class FieldEncryptor
{
    /**
     * Encrypt specified fields in a data array.
     * Supports dot notation for nested fields (e.g., 'card.number').
     */
    public function encrypt(array $data, ?array $fields = null): array
    {
        $fields ??= $this->getEncryptedFields();

        foreach ($fields as $field) {
            $value = Arr::get($data, $field);

            if ($value === null) {
                continue;
            }

            try {
                $encrypted = is_string($value) ? Crypt::encryptString($value) : Crypt::encrypt($value);
                Arr::set($data, $field, $encrypted);
            } catch (\Throwable $e) {
                Log::warning("Billing: Failed to encrypt field '{$field}'", ['error' => $e->getMessage()]);
            }
        }

        return $data;
    }

    /**
     * Decrypt specified fields in a data array.
     * Gracefully falls back to the original value if decryption fails
     * (supports migrating from unencrypted to encrypted data).
     */
    public function decrypt(array $data, ?array $fields = null): array
    {
        $fields ??= $this->getEncryptedFields();

        foreach ($fields as $field) {
            $value = Arr::get($data, $field);

            if ($value === null) {
                continue;
            }

            try {
                $decrypted = is_string($value) ? Crypt::decryptString($value) : Crypt::decrypt((string) $value);
                Arr::set($data, $field, $decrypted);
            } catch (\Throwable) {
                // Value is likely not encrypted (migration period) — keep as-is
            }
        }

        return $data;
    }

    /**
     * Mask a value for display (e.g., "2547****5678").
     */
    public function mask(string $value, int $showFirst = 4, int $showLast = 4): string
    {
        $length = mb_strlen($value);

        if ($length <= $showFirst + $showLast) {
            return str_repeat('*', $length);
        }

        $first = mb_substr($value, 0, $showFirst);
        $last = mb_substr($value, -$showLast);
        $middle = str_repeat('*', $length - $showFirst - $showLast);

        return $first.$middle.$last;
    }

    /**
     * Hash a value for storage (irreversible, for audit logs).
     */
    public function hash(string $value): string
    {
        return hash('sha256', $value.config('app.key'));
    }

    /**
     * Scrub sensitive keys from data before logging.
     */
    public function scrubForLogging(array $data): array
    {
        $scrubKeys = config('billing.security.scrub_keys', [
            'token', 'secret', 'password', 'api_key', 'consumer_secret',
            'auth_token', 'passkey', 'card_number', 'cvv', 'pin',
        ]);

        return $this->recursiveScrub($data, $scrubKeys);
    }

    protected function recursiveScrub(array $data, array $scrubKeys): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->recursiveScrub($value, $scrubKeys);
            } elseif (is_string($key) && in_array(strtolower($key), $scrubKeys, true)) {
                $data[$key] = '***REDACTED***';
            }
        }

        return $data;
    }

    public function isEnabled(): bool
    {
        return (bool) config('billing.security.encrypt_at_rest', false);
    }

    /**
     * @return array<string>
     */
    public function getEncryptedFields(): array
    {
        return config('billing.security.encrypted_fields', [
            'phone',
            'email',
            'card_exp_month',
            'card_exp_year',
            'token',
            'bank_name',
        ]);
    }
}

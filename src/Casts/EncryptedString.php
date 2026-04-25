<?php

declare(strict_types=1);

namespace Moffhub\Billing\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts a string value at rest in the database.
 * Gracefully falls back to plaintext during migration periods.
 *
 * Usage in model:
 *   protected function casts(): array {
 *       return ['phone' => EncryptedString::class];
 *   }
 *
 * @implements CastsAttributes<string, string|int|float|bool>
 */
class EncryptedString implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        if (! config('billing.security.encrypt_at_rest', false)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            // Not encrypted yet (migration period) — return as-is
            return $value;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => $value ? '1' : '0',
        };

        if (! config('billing.security.encrypt_at_rest', false)) {
            return $stringValue;
        }

        return Crypt::encryptString($stringValue);
    }
}

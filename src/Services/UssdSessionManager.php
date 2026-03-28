<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Support\Facades\Cache;

class UssdSessionManager
{
    /**
     * Get session data for a given USSD session ID.
     *
     * @return array<string, mixed>
     */
    public function get(string $sessionId): array
    {
        return Cache::get($this->key($sessionId), []);
    }

    /**
     * Store session data for a given USSD session ID.
     *
     * @param  array<string, mixed>  $data
     */
    public function put(string $sessionId, array $data): void
    {
        $ttl = (int) config('billing.ussd.session_ttl', 300);

        Cache::put($this->key($sessionId), $data, $ttl);
    }

    /**
     * Update specific keys in the session data.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $sessionId, array $data): void
    {
        $existing = $this->get($sessionId);

        $this->put($sessionId, array_merge($existing, $data));
    }

    /**
     * Remove session data.
     */
    public function forget(string $sessionId): void
    {
        Cache::forget($this->key($sessionId));
    }

    /**
     * Check if a session exists.
     */
    public function has(string $sessionId): bool
    {
        return Cache::has($this->key($sessionId));
    }

    /**
     * Build the cache key for a session.
     */
    protected function key(string $sessionId): string
    {
        return 'billing_ussd_session:'.$sessionId;
    }
}

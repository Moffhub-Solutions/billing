<?php

declare(strict_types=1);

namespace Moffhub\Billing;

use Illuminate\Foundation\Application;
use Illuminate\Support\Manager;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Providers\ManualProvider;

class PaymentManager extends Manager
{
    protected Application $app;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->app = $app;
    }

    /**
     * Create the M-Pesa payment driver.
     * Placeholder — full implementation in Phase 3.
     */
    public function createMpesaDriver(): PaymentProviderInterface
    {
        // Will be: return new MpesaProvider(config('billing.providers.mpesa'));
        throw new \RuntimeException('M-Pesa provider not yet implemented. Coming in Phase 3.');
    }

    /**
     * Create the Paystack payment driver.
     * Placeholder — full implementation in Phase 3.
     */
    public function createPaystackDriver(): PaymentProviderInterface
    {
        throw new \RuntimeException('Paystack provider not yet implemented. Coming in Phase 3.');
    }

    /**
     * Create the Flutterwave payment driver.
     * Placeholder — full implementation in Phase 3.
     */
    public function createFlutterwaveDriver(): PaymentProviderInterface
    {
        throw new \RuntimeException('Flutterwave provider not yet implemented. Coming in Phase 3.');
    }

    /**
     * Create the Pesapal payment driver.
     * Placeholder — full implementation in Phase 3.
     */
    public function createPesapalDriver(): PaymentProviderInterface
    {
        throw new \RuntimeException('Pesapal provider not yet implemented. Coming in Phase 3.');
    }

    /**
     * Create the manual/offline payment driver.
     */
    public function createManualDriver(): PaymentProviderInterface
    {
        return new ManualProvider;
    }

    public function getDefaultDriver(): string
    {
        return $this->app['config']['billing.default_provider'] ?? 'manual';
    }

    /**
     * Get all available provider names.
     *
     * @return array<string>
     */
    public function getAvailableProviders(): array
    {
        return ['mpesa', 'paystack', 'flutterwave', 'pesapal', 'manual'];
    }

    /**
     * Check if a provider is configured.
     */
    public function isProviderConfigured(string $provider): bool
    {
        $config = $this->app['config']["billing.providers.{$provider}"] ?? [];

        return match ($provider) {
            'mpesa' => ! empty($config['consumer_key']) && ! empty($config['consumer_secret']),
            'paystack' => ! empty($config['secret_key']),
            'flutterwave' => ! empty($config['secret_key']),
            'pesapal' => ! empty($config['consumer_key']) && ! empty($config['consumer_secret']),
            'manual' => true,
            default => false,
        };
    }
}

<?php

declare(strict_types=1);

namespace Moffhub\Billing;

use Illuminate\Foundation\Application;
use Illuminate\Support\Manager;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Providers\FlutterwaveProvider;
use Moffhub\Billing\Providers\ManualProvider;
use Moffhub\Billing\Providers\MpesaProvider;
use Moffhub\Billing\Providers\PaystackProvider;
use Moffhub\Billing\Providers\PesapalProvider;

class PaymentManager extends Manager
{
    protected Application $app;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->app = $app;
    }

    /**
     * Create the M-Pesa payment driver (Safaricom Daraja API).
     */
    public function createMpesaDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.mpesa'] ?? [];

        return new MpesaProvider(
            consumerKey: $config['consumer_key'] ?? '',
            consumerSecret: $config['consumer_secret'] ?? '',
            shortcode: $config['shortcode'] ?? '',
            passkey: $config['passkey'] ?? '',
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            timeoutUrl: $config['timeout_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
            initiatorName: $config['initiator_name'] ?? null,
            initiatorPassword: $config['initiator_password'] ?? null,
            certificatePath: $config['certificate_path'] ?? null,
        );
    }

    /**
     * Create the Paystack payment driver.
     */
    public function createPaystackDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.paystack'] ?? [];

        return new PaystackProvider(
            secretKey: $config['secret_key'] ?? '',
            publicKey: $config['public_key'] ?? '',
            webhookSecret: $config['webhook_secret'] ?? '',
            baseUrl: $config['base_url'] ?? 'https://api.paystack.co',
        );
    }

    /**
     * Create the Flutterwave payment driver.
     */
    public function createFlutterwaveDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.flutterwave'] ?? [];

        return new FlutterwaveProvider(
            secretKey: $config['secret_key'] ?? '',
            publicKey: $config['public_key'] ?? '',
            encryptionKey: $config['encryption_key'] ?? '',
            webhookSecret: $config['webhook_secret'] ?? '',
            baseUrl: $config['base_url'] ?? 'https://api.flutterwave.com/v3',
        );
    }

    /**
     * Create the Pesapal payment driver (Pesapal API v3).
     */
    public function createPesapalDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.pesapal'] ?? [];

        return new PesapalProvider(
            consumerKey: $config['consumer_key'] ?? '',
            consumerSecret: $config['consumer_secret'] ?? '',
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
            ipnId: $config['ipn_id'] ?? null,
        );
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

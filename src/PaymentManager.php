<?php

declare(strict_types=1);

namespace Moffhub\Billing;

use Illuminate\Foundation\Application;
use Illuminate\Support\Manager;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Providers\AirtelMoneyProvider;
use Moffhub\Billing\Providers\CoopBankProvider;
use Moffhub\Billing\Providers\FlutterwaveProvider;
use Moffhub\Billing\Providers\IntaSendProvider;
use Moffhub\Billing\Providers\JengaProvider;
use Moffhub\Billing\Providers\KcbBuniProvider;
use Moffhub\Billing\Providers\ManualProvider;
use Moffhub\Billing\Providers\MpesaProvider;
use Moffhub\Billing\Providers\NcbaProvider;
use Moffhub\Billing\Providers\PayOrchestraProvider;
use Moffhub\Billing\Providers\PaystackProvider;
use Moffhub\Billing\Providers\PesapalProvider;
use Moffhub\Billing\Providers\StanbicProvider;

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
     * Create the Airtel Money payment driver.
     */
    public function createAirtelDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.airtel'] ?? [];

        return new AirtelMoneyProvider(
            clientId: $config['client_id'] ?? '',
            clientSecret: $config['client_secret'] ?? '',
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
            country: $config['country'] ?? 'KE',
            currency: $config['currency'] ?? 'KES',
        );
    }

    /**
     * Create the KCB BUNI payment driver.
     */
    public function createKcbDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.kcb'] ?? [];

        return new KcbBuniProvider(
            apiKey: $config['api_key'] ?? '',
            apiSecret: $config['api_secret'] ?? '',
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
            merchantCode: $config['merchant_code'] ?? '',
        );
    }

    /**
     * Create the Equity Jenga API payment driver.
     */
    public function createJengaDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.jenga'] ?? [];

        return new JengaProvider(
            apiKey: $config['api_key'] ?? '',
            merchantCode: $config['merchant_code'] ?? '',
            consumerSecret: $config['consumer_secret'] ?? '',
            privateKeyPath: $config['private_key_path'] ?? null,
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
        );
    }

    /**
     * Create the Co-operative Bank Connect payment driver.
     */
    public function createCoopbankDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.coopbank'] ?? [];

        return new CoopBankProvider(
            consumerKey: $config['consumer_key'] ?? '',
            consumerSecret: $config['consumer_secret'] ?? '',
            accountNumber: $config['account_number'] ?? '',
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
        );
    }

    /**
     * Create the Stanbic Bank payment driver.
     */
    public function createStanbicDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.stanbic'] ?? [];

        return new StanbicProvider(
            apiKey: $config['api_key'] ?? '',
            apiSecret: $config['api_secret'] ?? '',
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
            merchantCode: $config['merchant_code'] ?? '',
        );
    }

    /**
     * Create the NCBA Bank payment driver.
     */
    public function createNcbaDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.ncba'] ?? [];

        return new NcbaProvider(
            apiKey: $config['api_key'] ?? '',
            apiSecret: $config['api_secret'] ?? '',
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
        );
    }

    /**
     * Create the IntaSend payment driver.
     */
    public function createIntasendDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.intasend'] ?? [];

        return new IntaSendProvider(
            publishableKey: $config['publishable_key'] ?? '',
            secretKey: $config['secret_key'] ?? '',
            environment: $config['environment'] ?? 'sandbox',
            callbackUrl: $config['callback_url'] ?? '',
            baseUrl: $config['base_url'] ?? null,
        );
    }

    /**
     * Create the PayOrchestra payment driver (multi-channel orchestration backbone).
     */
    public function createPayorchestraDriver(): PaymentProviderInterface
    {
        $config = $this->app['config']['billing.providers.payorchestra'] ?? [];

        return new PayOrchestraProvider(
            apiKey: $config['api_key'] ?? '',
            orgId: $config['org_id'] ?? '',
            webhookSecret: $config['webhook_secret'] ?? '',
            baseUrl: $config['base_url'] ?? 'https://backbone.payorchestra.com',
            timeout: (int) ($config['timeout'] ?? 30),
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
        return ['payorchestra', 'mpesa', 'paystack', 'flutterwave', 'pesapal', 'airtel', 'kcb', 'jenga', 'coopbank', 'stanbic', 'ncba', 'intasend', 'manual'];
    }

    /**
     * Check if a provider is configured.
     */
    public function isProviderConfigured(string $provider): bool
    {
        $config = $this->app['config']["billing.providers.{$provider}"] ?? [];

        return match ($provider) {
            'payorchestra' => ! empty($config['api_key']) && ! empty($config['org_id']),
            'mpesa' => ! empty($config['consumer_key']) && ! empty($config['consumer_secret']),
            'paystack' => ! empty($config['secret_key']),
            'flutterwave' => ! empty($config['secret_key']),
            'pesapal' => ! empty($config['consumer_key']) && ! empty($config['consumer_secret']),
            'airtel' => ! empty($config['client_id']) && ! empty($config['client_secret']),
            'kcb' => ! empty($config['api_key']) && ! empty($config['api_secret']),
            'jenga' => ! empty($config['api_key']) && ! empty($config['consumer_secret']),
            'coopbank' => ! empty($config['consumer_key']) && ! empty($config['consumer_secret']),
            'stanbic' => ! empty($config['api_key']) && ! empty($config['api_secret']),
            'ncba' => ! empty($config['api_key']),
            'intasend' => ! empty($config['publishable_key']) && ! empty($config['secret_key']),
            'manual' => true,
            default => false,
        };
    }
}

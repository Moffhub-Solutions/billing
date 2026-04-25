<?php

declare(strict_types=1);

namespace Moffhub\Billing;

use Illuminate\Config\Repository;
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
        $prefix = 'billing.providers.mpesa.';

        return new MpesaProvider(
            consumerKey: $this->configString($prefix.'consumer_key'),
            consumerSecret: $this->configString($prefix.'consumer_secret'),
            shortcode: $this->configString($prefix.'shortcode'),
            passkey: $this->configString($prefix.'passkey'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            timeoutUrl: $this->configString($prefix.'timeout_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
            initiatorName: $this->configNullableString($prefix.'initiator_name'),
            initiatorPassword: $this->configNullableString($prefix.'initiator_password'),
            certificatePath: $this->configNullableString($prefix.'certificate_path'),
        );
    }

    /**
     * Create the Paystack payment driver.
     */
    public function createPaystackDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.paystack.';

        return new PaystackProvider(
            secretKey: $this->configString($prefix.'secret_key'),
            publicKey: $this->configString($prefix.'public_key'),
            webhookSecret: $this->configString($prefix.'webhook_secret'),
            baseUrl: $this->configString($prefix.'base_url', 'https://api.paystack.co'),
        );
    }

    /**
     * Create the Flutterwave payment driver.
     */
    public function createFlutterwaveDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.flutterwave.';

        return new FlutterwaveProvider(
            secretKey: $this->configString($prefix.'secret_key'),
            publicKey: $this->configString($prefix.'public_key'),
            encryptionKey: $this->configString($prefix.'encryption_key'),
            webhookSecret: $this->configString($prefix.'webhook_secret'),
            baseUrl: $this->configString($prefix.'base_url', 'https://api.flutterwave.com/v3'),
        );
    }

    /**
     * Create the Pesapal payment driver (Pesapal API v3).
     */
    public function createPesapalDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.pesapal.';

        return new PesapalProvider(
            consumerKey: $this->configString($prefix.'consumer_key'),
            consumerSecret: $this->configString($prefix.'consumer_secret'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
            ipnId: $this->configNullableString($prefix.'ipn_id'),
        );
    }

    /**
     * Create the Airtel Money payment driver.
     */
    public function createAirtelDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.airtel.';

        return new AirtelMoneyProvider(
            clientId: $this->configString($prefix.'client_id'),
            clientSecret: $this->configString($prefix.'client_secret'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
            country: $this->configString($prefix.'country', 'KE'),
            currency: $this->configString($prefix.'currency', 'KES'),
        );
    }

    /**
     * Create the KCB BUNI payment driver.
     */
    public function createKcbDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.kcb.';

        return new KcbBuniProvider(
            apiKey: $this->configString($prefix.'api_key'),
            apiSecret: $this->configString($prefix.'api_secret'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
            merchantCode: $this->configString($prefix.'merchant_code'),
        );
    }

    /**
     * Create the Equity Jenga API payment driver.
     */
    public function createJengaDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.jenga.';

        return new JengaProvider(
            apiKey: $this->configString($prefix.'api_key'),
            merchantCode: $this->configString($prefix.'merchant_code'),
            consumerSecret: $this->configString($prefix.'consumer_secret'),
            privateKeyPath: $this->configNullableString($prefix.'private_key_path'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
        );
    }

    /**
     * Create the Co-operative Bank Connect payment driver.
     */
    public function createCoopbankDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.coopbank.';

        return new CoopBankProvider(
            consumerKey: $this->configString($prefix.'consumer_key'),
            consumerSecret: $this->configString($prefix.'consumer_secret'),
            accountNumber: $this->configString($prefix.'account_number'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
        );
    }

    /**
     * Create the Stanbic Bank payment driver.
     */
    public function createStanbicDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.stanbic.';

        return new StanbicProvider(
            apiKey: $this->configString($prefix.'api_key'),
            apiSecret: $this->configString($prefix.'api_secret'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
            merchantCode: $this->configString($prefix.'merchant_code'),
        );
    }

    /**
     * Create the NCBA Bank payment driver.
     */
    public function createNcbaDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.ncba.';

        return new NcbaProvider(
            apiKey: $this->configString($prefix.'api_key'),
            apiSecret: $this->configString($prefix.'api_secret'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
        );
    }

    /**
     * Create the IntaSend payment driver.
     */
    public function createIntasendDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.intasend.';

        return new IntaSendProvider(
            publishableKey: $this->configString($prefix.'publishable_key'),
            secretKey: $this->configString($prefix.'secret_key'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
        );
    }

    /**
     * Create the PayOrchestra payment driver (multi-channel orchestration backbone).
     */
    public function createPayorchestraDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.payorchestra.';

        return new PayOrchestraProvider(
            apiKey: $this->configString($prefix.'api_key'),
            orgId: $this->configString($prefix.'org_id'),
            webhookSecret: $this->configString($prefix.'webhook_secret'),
            baseUrl: $this->configString($prefix.'base_url', 'https://backbone.payorchestra.com'),
            timeout: $this->configInt($prefix.'timeout', 30),
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
        return $this->configString('billing.default_provider', 'manual');
    }

    /**
     * Get all available provider names.
     *
     * @return array<int, string>
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
        $prefix = "billing.providers.{$provider}.";

        return match ($provider) {
            'payorchestra' => $this->configString($prefix.'api_key') !== '' && $this->configString($prefix.'org_id') !== '',
            'mpesa' => $this->configString($prefix.'consumer_key') !== '' && $this->configString($prefix.'consumer_secret') !== '',
            'paystack' => $this->configString($prefix.'secret_key') !== '',
            'flutterwave' => $this->configString($prefix.'secret_key') !== '',
            'pesapal' => $this->configString($prefix.'consumer_key') !== '' && $this->configString($prefix.'consumer_secret') !== '',
            'airtel' => $this->configString($prefix.'client_id') !== '' && $this->configString($prefix.'client_secret') !== '',
            'kcb' => $this->configString($prefix.'api_key') !== '' && $this->configString($prefix.'api_secret') !== '',
            'jenga' => $this->configString($prefix.'api_key') !== '' && $this->configString($prefix.'consumer_secret') !== '',
            'coopbank' => $this->configString($prefix.'consumer_key') !== '' && $this->configString($prefix.'consumer_secret') !== '',
            'stanbic' => $this->configString($prefix.'api_key') !== '' && $this->configString($prefix.'api_secret') !== '',
            'ncba' => $this->configString($prefix.'api_key') !== '',
            'intasend' => $this->configString($prefix.'publishable_key') !== '' && $this->configString($prefix.'secret_key') !== '',
            'manual' => true,
            default => false,
        };
    }

    private function config(): Repository
    {
        return $this->app->make('config');
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = $this->config()->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function configNullableString(string $key): ?string
    {
        $value = $this->config()->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function configInt(string $key, int $default = 0): int
    {
        $value = $this->config()->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}

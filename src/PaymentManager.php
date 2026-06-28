<?php

declare(strict_types=1);

namespace Moffhub\Billing;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Manager;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Enums\PaymentMethod;
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
use Moffhub\Billing\Providers\TkashProvider;

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
     * Create the T-Kash payment driver (Telkom Kenya mobile money).
     */
    public function createTkashDriver(): PaymentProviderInterface
    {
        $prefix = 'billing.providers.tkash.';

        return new TkashProvider(
            consumerKey: $this->configString($prefix.'consumer_key'),
            consumerSecret: $this->configString($prefix.'consumer_secret'),
            consumerId: $this->configString($prefix.'consumer_id'),
            grantUsername: $this->configString($prefix.'grant_username'),
            grantPassword: $this->configString($prefix.'grant_password'),
            b2cUsername: $this->configString($prefix.'b2c_username'),
            b2cPassword: $this->configString($prefix.'b2c_password'),
            environment: $this->configString($prefix.'environment', 'sandbox'),
            callbackUrl: $this->configString($prefix.'callback_url'),
            validationUrl: $this->configString($prefix.'validation_url'),
            baseUrl: $this->configNullableString($prefix.'base_url'),
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
        return ['payorchestra', 'mpesa', 'paystack', 'flutterwave', 'pesapal', 'airtel', 'tkash', 'kcb', 'jenga', 'coopbank', 'stanbic', 'ncba', 'intasend', 'manual'];
    }

    /**
     * Get the providers offered to customers at checkout.
     *
     * Resolves `billing.enabled_providers` (a curated, ordered subset) down to
     * those that are actually configured. When the config list is empty, every
     * configured provider is offered.
     *
     * @return array<int, string>
     */
    public function getEnabledProviders(): array
    {
        $configured = array_values(array_filter(
            $this->getAvailableProviders(),
            fn (string $provider): bool => $this->isProviderConfigured($provider),
        ));

        $enabledRaw = $this->config()->get('billing.enabled_providers', []);
        $enabled = is_array($enabledRaw)
            ? array_values(array_filter($enabledRaw, fn ($v): bool => is_string($v) && $v !== ''))
            : [];

        if ($enabled === []) {
            return $configured;
        }

        // Preserve the configured order of the curated list.
        return array_values(array_filter($enabled, fn (string $provider): bool => in_array($provider, $configured, true)));
    }

    /**
     * Build the selectable payment options for the checkout UI.
     *
     * Each option carries the provider key (passed back as `provider` when
     * initiating a payment), a display label, and the canonical payment method.
     *
     * @return array<int, array{provider: string, label: string, method: string}>
     */
    public function getPaymentOptions(): array
    {
        return array_map(fn (string $provider): array => [
            'provider' => $provider,
            'label' => $this->providerLabel($provider),
            'method' => $this->providerMethod($provider)->value,
        ], $this->getEnabledProviders());
    }

    /**
     * Human-readable label for a provider key.
     */
    public function providerLabel(string $provider): string
    {
        return match ($provider) {
            'mpesa' => 'M-Pesa',
            'airtel' => 'Airtel Money',
            'tkash' => 'T-Kash',
            'kcb' => 'KCB',
            'jenga' => 'Equity (Jenga)',
            'coopbank' => 'Co-operative Bank',
            'stanbic' => 'Stanbic Bank',
            'ncba' => 'NCBA',
            'intasend' => 'IntaSend',
            'paystack' => 'Paystack',
            'flutterwave' => 'Flutterwave',
            'pesapal' => 'Pesapal',
            'payorchestra' => 'PayOrchestra',
            'manual' => 'Manual/Cash',
            default => ucfirst($provider),
        };
    }

    /**
     * Map a provider key to its canonical payment method.
     */
    public function providerMethod(string $provider): PaymentMethod
    {
        return match ($provider) {
            'mpesa' => PaymentMethod::MPESA,
            'airtel' => PaymentMethod::AIRTEL_MONEY,
            'tkash' => PaymentMethod::TKASH,
            'kcb', 'jenga', 'coopbank', 'stanbic', 'ncba' => PaymentMethod::BANK,
            'paystack', 'flutterwave', 'pesapal' => PaymentMethod::CARD,
            'intasend', 'payorchestra' => PaymentMethod::MOBILE_MONEY,
            default => PaymentMethod::MANUAL,
        };
    }

    /**
     * Get a provider's per-transaction limits.
     *
     * Amounts are in cents. A null value means "no cap". Reads
     * `billing.providers.<provider>.limits`, falling back to no limits.
     *
     * @return array{max_amount: int|null, max_per_day: int|null}
     */
    public function getProviderLimits(string $provider): array
    {
        $raw = $this->config()->get("billing.providers.{$provider}.limits", []);
        $limits = is_array($raw) ? $raw : [];

        $maxAmount = $limits['max_amount'] ?? null;
        $maxPerDay = $limits['max_per_day'] ?? null;

        return [
            'max_amount' => is_numeric($maxAmount) && (int) $maxAmount > 0 ? (int) $maxAmount : null,
            'max_per_day' => is_numeric($maxPerDay) && (int) $maxPerDay > 0 ? (int) $maxPerDay : null,
        ];
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
            'tkash' => $this->configString($prefix.'consumer_key') !== '' && $this->configString($prefix.'consumer_secret') !== '' && $this->configString($prefix.'consumer_id') !== '',
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

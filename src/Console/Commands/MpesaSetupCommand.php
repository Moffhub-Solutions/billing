<?php

declare(strict_types=1);

namespace Moffhub\Billing\Console\Commands;

use Illuminate\Console\Command;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\MpesaProvider;

class MpesaSetupCommand extends Command
{
    protected $signature = 'billing:mpesa-setup
                            {--register-c2b : Register C2B validation and confirmation URLs with Safaricom}
                            {--response-type=Completed : C2B response type (Completed or Cancelled)}';

    protected $description = 'Check M-Pesa configuration status and optionally register C2B URLs';

    public function handle(PaymentManager $paymentManager): int
    {
        $this->info('M-Pesa Configuration Check');
        $this->line(str_repeat('─', 50));

        // Check basic configuration
        if (! $paymentManager->isProviderConfigured('mpesa')) {
            $this->error('M-Pesa is not configured. Set these environment variables:');
            $this->newLine();
            $this->line('  MPESA_CONSUMER_KEY=your_consumer_key');
            $this->line('  MPESA_CONSUMER_SECRET=your_consumer_secret');
            $this->line('  MPESA_SHORTCODE=your_shortcode');
            $this->line('  MPESA_PASSKEY=your_passkey');

            return self::FAILURE;
        }

        $provider = $paymentManager->driver('mpesa');

        if (! $provider instanceof MpesaProvider) {
            $this->error('M-Pesa driver is not an MpesaProvider instance.');

            return self::FAILURE;
        }

        $this->checkCredentials($provider);
        $this->checkCallbackUrls();
        $this->checkB2cCredentials();

        // Register C2B URLs if requested
        if ($this->option('register-c2b')) {
            return $this->registerC2bUrls($provider);
        }

        $this->newLine();
        $this->line('To register C2B URLs, run:');
        $this->line('  <fg=yellow>php artisan billing:mpesa-setup --register-c2b</>');

        return self::SUCCESS;
    }

    protected function checkCredentials(MpesaProvider $provider): void
    {
        $this->newLine();
        $this->info('Credentials:');

        $configured = $provider->isConfigured();
        $this->line('  Consumer Key/Secret: '.($configured ? '<fg=green>set</>' : '<fg=red>missing</>'));

        $shortcode = $this->configString('billing.providers.mpesa.shortcode');
        $this->line('  Shortcode: '.($shortcode !== '' ? "<fg=green>{$shortcode}</>" : '<fg=red>missing</>'));

        $passkey = $this->configString('billing.providers.mpesa.passkey');
        $this->line('  Passkey: '.($passkey !== '' ? '<fg=green>set</>' : '<fg=red>missing</>'));

        $environment = $this->configString('billing.providers.mpesa.environment', 'sandbox');
        $this->line("  Environment: <fg=cyan>{$environment}</>");

        // Test OAuth token
        try {
            $token = $provider->getAccessToken();
            $this->line('  OAuth Token: '.($token !== '' ? '<fg=green>valid</>' : '<fg=red>failed</>'));
        } catch (\Throwable $e) {
            $this->line('  OAuth Token: <fg=red>failed</> ('.$e->getMessage().')');
        }
    }

    protected function checkCallbackUrls(): void
    {
        $this->newLine();
        $this->info('Callback URLs:');

        $callbackUrl = $this->configString('billing.providers.mpesa.callback_url');
        $timeoutUrl = $this->configString('billing.providers.mpesa.timeout_url');
        $webhookPrefix = $this->configString('billing.webhooks.prefix', 'billing/webhooks');

        $this->line('  STK Callback: '.($callbackUrl !== '' ? "<fg=green>{$callbackUrl}</>" : '<fg=red>not set (MPESA_CALLBACK_URL)</>'));
        $this->line('  Timeout URL: '.($timeoutUrl !== '' ? "<fg=green>{$timeoutUrl}</>" : '<fg=yellow>not set (MPESA_TIMEOUT_URL)</>'));
        $this->line("  Webhook Route: <fg=cyan>{$webhookPrefix}/mpesa</>");

        // C2B URLs check
        $this->newLine();
        $this->info('C2B (Customer to Business):');

        $appUrl = $this->configString('app.url', 'http://localhost');
        $confirmationUrl = "{$appUrl}/{$webhookPrefix}/mpesa";
        $validationUrl = "{$appUrl}/{$webhookPrefix}/mpesa";

        $this->line("  Confirmation URL: <fg=cyan>{$confirmationUrl}</>");
        $this->line("  Validation URL: <fg=cyan>{$validationUrl}</>");
        $this->line('  Registration: <fg=yellow>unknown</> (Safaricom does not provide a query API)');
        $this->line('  <fg=gray>Run with --register-c2b to register these URLs with Safaricom</>');
    }

    protected function checkB2cCredentials(): void
    {
        $this->newLine();
        $this->info('B2C (Business to Customer):');

        $initiatorName = $this->configString('billing.providers.mpesa.initiator_name');
        $initiatorPassword = $this->configString('billing.providers.mpesa.initiator_password');
        $certPath = $this->configString('billing.providers.mpesa.certificate_path');

        $this->line('  Initiator Name: '.($initiatorName !== '' ? '<fg=green>set</>' : '<fg=yellow>not set (optional)</>'));
        $this->line('  Initiator Password: '.($initiatorPassword !== '' ? '<fg=green>set</>' : '<fg=yellow>not set (optional)</>'));

        if ($certPath !== '') {
            $exists = file_exists($certPath);
            $this->line('  Certificate: '.($exists ? "<fg=green>{$certPath}</>" : "<fg=red>{$certPath} (file not found)</>"));
        } else {
            $this->line('  Certificate: <fg=yellow>not set — will use sandbox default</>');
        }

        $b2cReady = $initiatorName !== '' && $initiatorPassword !== '';
        $this->line('  Status: '.($b2cReady ? '<fg=green>ready</>' : '<fg=yellow>not configured (refunds/disbursements disabled)</>'));
    }

    protected function registerC2bUrls(MpesaProvider $provider): int
    {
        $this->newLine();
        $this->info('Registering C2B URLs with Safaricom...');

        $appUrl = $this->configString('app.url', 'http://localhost');
        $webhookPrefix = $this->configString('billing.webhooks.prefix', 'billing/webhooks');
        $confirmationUrl = "{$appUrl}/{$webhookPrefix}/mpesa";
        $validationUrl = "{$appUrl}/{$webhookPrefix}/mpesa";

        $responseTypeRaw = $this->option('response-type');
        $responseType = is_string($responseTypeRaw) ? $responseTypeRaw : 'Completed';

        $this->line("  Confirmation URL: {$confirmationUrl}");
        $this->line("  Validation URL: {$validationUrl}");
        $this->line("  Response Type: {$responseType}");

        if (str_starts_with($appUrl, 'http://localhost') || str_starts_with($appUrl, 'http://127.')) {
            $this->warn('  APP_URL is set to localhost — Safaricom cannot reach this URL.');
            $this->warn('  Set APP_URL to a publicly accessible HTTPS URL before registering.');

            if (! $this->confirm('Continue anyway? (useful for sandbox testing)')) {
                return self::FAILURE;
            }
        }

        try {
            $result = $provider->registerC2bUrls($confirmationUrl, $validationUrl, $responseType);

            $responseCodeRaw = $result['ResponseCode'] ?? $result['ResponseDescription'] ?? 'unknown';
            $responseDescRaw = $result['ResponseDescription'] ?? json_encode($result);
            $responseDesc = is_string($responseDescRaw) ? $responseDescRaw : 'unknown';

            if (($result['ResponseCode'] ?? '') === '0') {
                $this->newLine();
                $this->info('C2B URLs registered successfully!');
                $this->line("  Response: {$responseDesc}");
                $this->newLine();
                $this->line('Customers can now pay to your paybill/till number.');
                $this->line('Payments will arrive at: <fg=green>'.$confirmationUrl.'</>');

                return self::SUCCESS;
            }

            unset($responseCodeRaw);

            $this->error("Registration failed: {$responseDesc}");
            $this->line('  Full response: '.(string) json_encode($result, JSON_PRETTY_PRINT));

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Registration failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}

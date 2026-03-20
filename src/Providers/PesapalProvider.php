<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PesapalProvider extends BasePaymentProvider
{
    protected string $consumerKey;

    protected string $consumerSecret;

    protected string $environment;

    protected string $callbackUrl;

    protected string $baseUrl;

    protected ?string $ipnId;

    public function __construct(
        string $consumerKey,
        string $consumerSecret,
        string $environment = 'sandbox',
        string $callbackUrl = '',
        ?string $baseUrl = null,
        ?string $ipnId = null,
    ) {
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
        $this->ipnId = $ipnId;
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $email = $options['email'] ?? null;
        $phone = $options['phone'] ?? null;

        if ($email === null && $phone === null) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Email or phone number is required for Pesapal.'],
            ];
        }

        return $this->submitOrder(
            merchantReference: $options['merchant_reference'] ?? uniqid('PSP-'),
            amount: $amount / 100, // convert cents to whole units
            currency: $currency,
            description: $options['description'] ?? 'Payment',
            callbackUrl: $options['callback_url'] ?? $this->callbackUrl,
            email: $email,
            phone: $phone,
            firstName: $options['first_name'] ?? null,
            lastName: $options['last_name'] ?? null,
        );
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $confirmationCode = $options['confirmation_code'] ?? $providerPaymentId;

        return $this->refundRequest(
            confirmationCode: $confirmationCode,
            amount: $amount !== null ? $amount / 100 : 0,
            username: $options['username'] ?? '',
            remarks: $options['remarks'] ?? 'Refund',
        );
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $result = $this->getTransactionStatus($providerPaymentId);

        return match ($result['status_code'] ?? 0) {
            1 => 'completed',
            2 => 'failed',
            3 => 'refunded',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        // Pesapal IPN sends OrderTrackingId — verify it exists
        $trackingId = $request->input('OrderTrackingId');

        return $trackingId !== null && $trackingId !== '';
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $trackingId = $request->input('OrderTrackingId');
        $merchantRef = $request->input('OrderMerchantReference');
        $notificationType = $request->input('OrderNotificationType');

        // IPN doesn't include status — must query the transaction
        $status = $trackingId ? $this->getTransactionStatus($trackingId) : [];

        $statusCode = $status['status_code'] ?? 0;

        return [
            'event' => match ($statusCode) {
                1 => 'payment.completed',
                2 => 'payment.failed',
                3 => 'payment.refunded',
                default => 'payment.pending',
            },
            'provider_payment_id' => $trackingId,
            'status' => match ($statusCode) {
                1 => 'completed',
                2 => 'failed',
                3 => 'refunded',
                default => 'pending',
            },
            'amount' => isset($status['amount']) ? (int) ($status['amount'] * 100) : null,
            'currency' => $status['currency'] ?? null,
            'metadata' => [
                'merchant_reference' => $merchantRef,
                'notification_type' => $notificationType,
                'payment_method' => $status['payment_method'] ?? null,
                'confirmation_code' => $status['confirmation_code'] ?? null,
                'payment_account' => $status['payment_account'] ?? null,
                'status_description' => $status['payment_status_description'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return ! empty($this->consumerKey) && ! empty($this->consumerSecret);
    }

    #[\Override]
    public function getName(): string
    {
        return 'pesapal';
    }

    // ─── Authentication ────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        $cacheKey = 'billing:pesapal:access_token:'.$this->consumerKey;

        return Cache::remember($cacheKey, 240, function (): string {
            $response = Http::post($this->baseUrl.'/api/Auth/RequestToken', [
                'consumer_key' => $this->consumerKey,
                'consumer_secret' => $this->consumerSecret,
            ]);

            return $response->json('token') ?? '';
        });
    }

    // ─── IPN Registration ──────────────────────────────────────────────

    /**
     * Register an IPN URL with Pesapal. Call once and store the ipn_id.
     */
    public function registerIpnUrl(string $url, string $notificationType = 'GET'): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/api/URLSetup/RegisterIPN', [
                'url' => $url,
                'ipn_notification_type' => $notificationType,
            ]);

        $data = $response->json() ?? [];

        if (isset($data['ipn_id'])) {
            $this->ipnId = $data['ipn_id'];
        }

        return $data;
    }

    // ─── Submit Order ──────────────────────────────────────────────────

    public function submitOrder(
        string $merchantReference,
        float $amount,
        string $currency = 'KES',
        string $description = 'Payment',
        ?string $callbackUrl = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $firstName = null,
        ?string $lastName = null,
    ): array {
        $token = $this->getAccessToken();

        $payload = [
            'id' => substr($merchantReference, 0, 50),
            'currency' => $currency,
            'amount' => $amount,
            'description' => substr($description, 0, 100),
            'callback_url' => $callbackUrl ?? $this->callbackUrl,
            'notification_id' => $this->ipnId ?? '',
            'billing_address' => array_filter([
                'email_address' => $email,
                'phone_number' => $phone,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'country_code' => 'KE',
            ]),
        ];

        $this->logRequest('POST', $this->baseUrl.'/api/Transactions/SubmitOrderRequest', $payload);

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/api/Transactions/SubmitOrderRequest', $payload);

        $data = $response->json() ?? [];

        $success = isset($data['order_tracking_id']) && $data['error'] === null;

        return [
            'success' => $success,
            'provider_payment_id' => $data['order_tracking_id'] ?? null,
            'provider_reference' => $data['merchant_reference'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => [
                'redirect_url' => $data['redirect_url'] ?? null,
                ...$data,
            ],
        ];
    }

    // ─── Transaction Status ────────────────────────────────────────────

    public function getTransactionStatus(string $orderTrackingId): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get($this->baseUrl.'/api/Transactions/GetTransactionStatus', [
                'orderTrackingId' => $orderTrackingId,
            ]);

        return $response->json() ?? [];
    }

    // ─── Refund ────────────────────────────────────────────────────────

    public function refundRequest(
        string $confirmationCode,
        float $amount,
        string $username = '',
        string $remarks = 'Refund',
    ): array {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/api/Transactions/RefundRequest', [
                'confirmation_code' => $confirmationCode,
                'amount' => $amount,
                'username' => $username,
                'remarks' => $remarks,
            ]);

        $data = $response->json() ?? [];
        $success = ($data['status'] ?? 0) === 200 || ($data['status'] ?? '') === '200';

        return [
            'success' => $success,
            'provider_refund_id' => $confirmationCode,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://pay.pesapal.com/v3'
            : 'https://cybqa.pesapal.com/pesapalv3';
    }
}

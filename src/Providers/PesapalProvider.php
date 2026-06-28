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
        $email = $this->optionNullableString($options, 'email');
        $phone = $this->optionNullableString($options, 'phone');

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
            merchantReference: $this->optionString($options, 'merchant_reference', uniqid('PSP-')),
            amount: $amount / 100, // convert cents to whole units
            currency: $currency,
            description: $this->optionString($options, 'description', 'Payment'),
            callbackUrl: $this->optionString($options, 'callback_url', $this->callbackUrl),
            email: $email,
            phone: $phone,
            firstName: $this->optionNullableString($options, 'first_name'),
            lastName: $this->optionNullableString($options, 'last_name'),
        );
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $confirmationCode = $this->optionString($options, 'confirmation_code', $providerPaymentId);

        return $this->refundRequest(
            confirmationCode: $confirmationCode,
            amount: $amount !== null ? $amount / 100 : 0,
            username: $this->optionString($options, 'username'),
            remarks: $this->optionString($options, 'remarks', 'Refund'),
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

        return is_string($trackingId) && $trackingId !== '';
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $trackingIdRaw = $request->input('OrderTrackingId');
        $trackingId = is_string($trackingIdRaw) ? $trackingIdRaw : null;
        $merchantRef = $request->input('OrderMerchantReference');
        $notificationType = $request->input('OrderNotificationType');

        // IPN doesn't include status — must query the transaction
        $status = $trackingId !== null ? $this->getTransactionStatus($trackingId) : [];

        $statusCode = $status['status_code'] ?? 0;
        $amountValue = $status['amount'] ?? null;
        $amount = is_numeric($amountValue) ? (int) ($amountValue * 100) : null;

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
            'amount' => $amount,
            'currency' => isset($status['currency']) && is_string($status['currency']) ? $status['currency'] : null,
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
        return $this->consumerKey !== '' && $this->consumerSecret !== '';
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

        /** @var string $token */
        $token = Cache::remember($cacheKey, 240, function (): string {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::post($this->baseUrl.'/api/Auth/RequestToken', [
                'consumer_key' => $this->consumerKey,
                'consumer_secret' => $this->consumerSecret,
            ]);

            $value = $response->json('token');

            return is_string($value) ? $value : '';
        });

        return $token;
    }

    // ─── IPN Registration ──────────────────────────────────────────────

    /**
     * Register an IPN URL with Pesapal. Call once and store the ipn_id.
     *
     * @return array<string, mixed>
     */
    public function registerIpnUrl(string $url, string $notificationType = 'GET'): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/api/URLSetup/RegisterIPN', [
                'url' => $url,
                'ipn_notification_type' => $notificationType,
            ]);

        $data = $this->asArray($response->json());
        /** @var array<string, mixed> $data */
        $ipnId = $data['ipn_id'] ?? null;

        if (is_string($ipnId)) {
            $this->ipnId = $ipnId;
        }

        return $data;
    }

    // ─── Submit Order ──────────────────────────────────────────────────

    /**
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
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

        $data = $this->asArray($response->json());

        $orderTrackingId = $data['order_tracking_id'] ?? null;
        $success = is_string($orderTrackingId) && $orderTrackingId !== '' && ($data['error'] ?? null) === null;

        return [
            'success' => $success,
            'provider_payment_id' => is_string($orderTrackingId) ? $orderTrackingId : null,
            'provider_reference' => isset($data['merchant_reference']) && is_string($data['merchant_reference']) ? $data['merchant_reference'] : null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => [
                'redirect_url' => $data['redirect_url'] ?? null,
                ...$data,
            ],
        ];
    }

    // ─── Transaction Status ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function getTransactionStatus(string $orderTrackingId): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get($this->baseUrl.'/api/Transactions/GetTransactionStatus', [
                'orderTrackingId' => $orderTrackingId,
            ]);

        return $this->asArray($response->json());
    }

    // ─── Refund ────────────────────────────────────────────────────────

    /**
     * @return array{success: bool, provider_refund_id: string|null, status: string, metadata: array<string, mixed>}
     */
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

        $data = $this->asArray($response->json());
        $statusValue = $data['status'] ?? 0;
        $success = $statusValue === 200 || $statusValue === '200';

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

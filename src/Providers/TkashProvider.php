<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * T-Kash (Telkom Kenya mobile money) payment provider.
 *
 * Integrates the T-Kash gateway (MFS / mfs-tkl.com), which follows a C2B
 * paybill model: you register validation/confirmation URLs once, the customer
 * pays your consumer (paybill) shortcode, and the gateway notifies you via the
 * confirmation callback. Disbursements (refunds/payouts) use the B2C channel.
 *
 * Endpoints and the OAuth flow follow T-Kash API v1.4.1. The collection and
 * disbursement request bodies are the v3 transactional conventions; confirm
 * the exact field set against your merchant onboarding pack, as Telkom does
 * not publish an open spec.
 *
 * @see https://github.com/3xplisit/TKash-API Community reference (v1.4.1)
 */
class TkashProvider extends BasePaymentProvider
{
    protected string $consumerKey;

    protected string $consumerSecret;

    protected string $consumerId;

    protected string $grantUsername;

    protected string $grantPassword;

    protected string $b2cUsername;

    protected string $b2cPassword;

    protected string $environment;

    protected string $callbackUrl;

    protected string $validationUrl;

    protected string $baseUrl;

    protected string $currency;

    public function __construct(
        string $consumerKey,
        string $consumerSecret,
        string $consumerId,
        string $grantUsername = '',
        string $grantPassword = '',
        string $b2cUsername = '',
        string $b2cPassword = '',
        string $environment = 'sandbox',
        string $callbackUrl = '',
        string $validationUrl = '',
        ?string $baseUrl = null,
        string $currency = 'KES',
    ) {
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->consumerId = $consumerId;
        $this->grantUsername = $grantUsername;
        $this->grantPassword = $grantPassword;
        $this->b2cUsername = $b2cUsername;
        $this->b2cPassword = $b2cPassword;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->validationUrl = $validationUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
        $this->currency = $currency;
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $phone = $this->optionNullableString($options, 'phone');

        if ($phone === null) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Phone number is required for T-Kash collection.'],
            ];
        }

        $reference = $this->optionString($options, 'reference', 'PAY-'.uniqid());
        $effectiveCurrency = $currency !== '' ? $currency : $this->currency;

        // C2B charge request. T-Kash collections are customer-to-business: the
        // amount is debited from the subscriber's wallet against the consumer
        // (paybill) shortcode. Completion is delivered to the confirmation URL.
        $payload = [
            'chargeC2BRequest' => [
                'consumerId' => $this->consumerId,
                'msisdn' => $this->formatPhone($phone),
                'amount' => (int) ceil($amount / 100), // cents to whole units
                'currency' => $effectiveCurrency,
                'referenceId' => $reference,
                'narration' => $this->optionString($options, 'description', 'Payment'),
                'callbackUrl' => $this->optionString($options, 'callback_url', $this->callbackUrl),
            ],
        ];

        $response = $this->request('consumer/v3/c2b/charge', $payload, 'POST');

        $data = $this->asArray($response->json());
        $success = $this->isAcceptedResponse($response);

        $providerPaymentId = $this->jsonNullableString($response, 'response.transactionId')
            ?? $this->jsonNullableString($response, 'transactionId')
            ?? $reference;

        return [
            'success' => $success,
            'provider_payment_id' => $providerPaymentId,
            'provider_reference' => $this->jsonNullableString($response, 'response.referenceId') ?? $reference,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $phone = $this->optionNullableString($options, 'phone');

        if ($phone === null) {
            return [
                'success' => false,
                'provider_refund_id' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Phone number is required for T-Kash B2C disbursement.'],
            ];
        }

        if ($this->b2cUsername === '' || $this->b2cPassword === '') {
            return [
                'success' => false,
                'provider_refund_id' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'B2C disbursement requires b2c_username and b2c_password.'],
            ];
        }

        $reference = $this->optionString($options, 'reference', 'REF-'.uniqid());

        $payload = [
            'b2cRequest' => [
                'consumerId' => $this->consumerId,
                'initiatorUsername' => $this->b2cUsername,
                'securityCredential' => $this->b2cPassword,
                'msisdn' => $this->formatPhone($phone),
                'amount' => $amount !== null ? (int) ceil($amount / 100) : 0,
                'currency' => $this->currency,
                'referenceId' => $reference,
                'remarks' => $this->optionString($options, 'remarks', "Refund for {$providerPaymentId}"),
                'callbackUrl' => $this->optionString($options, 'callback_url', $this->callbackUrl),
            ],
        ];

        $response = $this->request('disbursement/v3/b2c', $payload, 'POST');

        $data = $this->asArray($response->json());
        $success = $this->isAcceptedResponse($response);

        return [
            'success' => $success,
            'provider_refund_id' => $this->jsonNullableString($response, 'response.transactionId')
                ?? $this->jsonNullableString($response, 'transactionId'),
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = $this->request(
            'consumer/v3/transactionStatus/'.rawurlencode($providerPaymentId),
            '',
            'GET',
        );

        $status = $this->jsonNullableString($response, 'response.status')
            ?? $this->jsonNullableString($response, 'status');

        return $this->mapTransactionStatus($status);
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        // T-Kash C2B callbacks are not signed (authenticity is URL secrecy + IP
        // allow-listing), so the payload is not self-authenticating. Report
        // "unverified": WebhookController confirms via async re-query (and the
        // optional URL secret / IP allowlist) before settling.
        return false;
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $transaction = $this->extractTransaction($request);

        $statusRaw = $transaction['status'] ?? $transaction['resultCode'] ?? $transaction['responseCode'] ?? null;
        $status = is_string($statusRaw) || is_int($statusRaw) ? (string) $statusRaw : null;
        $normalized = $this->mapTransactionStatus($status);
        $success = $normalized === 'completed';

        $amountRaw = $transaction['amount'] ?? $transaction['transAmount'] ?? null;
        $amount = is_numeric($amountRaw) ? (int) ((float) $amountRaw * 100) : null;

        $transactionId = $transaction['transactionId'] ?? $transaction['trxId'] ?? null;

        return [
            'event' => $success ? 'payment.completed' : ($normalized === 'failed' ? 'payment.failed' : 'payment.pending'),
            'provider_payment_id' => is_string($transactionId) ? $transactionId : null,
            'status' => $normalized,
            'amount' => $amount,
            'currency' => isset($transaction['currency']) && is_string($transaction['currency'])
                ? $transaction['currency']
                : $this->currency,
            'metadata' => [
                'tkash_receipt' => $transaction['receiptNumber'] ?? $transaction['mfsReceipt'] ?? null,
                'message' => $transaction['message'] ?? $transaction['resultDesc'] ?? null,
                'phone' => $transaction['msisdn'] ?? null,
                'reference' => $transaction['referenceId'] ?? $transaction['billRefNumber'] ?? null,
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return $this->consumerKey !== ''
            && $this->consumerSecret !== ''
            && $this->consumerId !== '';
    }

    #[\Override]
    public function getName(): string
    {
        return 'tkash';
    }

    // ─── C2B Setup (verified v3 endpoints) ─────────────────────────────

    /**
     * Register the validation and confirmation URLs with T-Kash. Call once per
     * consumer; the confirmation URL receives C2B payment notifications.
     *
     * @return array<string, mixed>
     */
    public function registerUrls(?string $confirmationUrl = null, ?string $validationUrl = null): array
    {
        $payload = [
            'registerUrlRequest' => [
                'consumerId' => $this->consumerId,
                'notificationUrl' => $confirmationUrl ?? $this->callbackUrl,
                'notificationUrlType' => 'REST',
                'validationUrl' => $validationUrl ?? $this->validationUrl,
                'validationUrlType' => 'REST',
                'creationDate' => now()->format('d-M-y\TH:i:s'),
            ],
        ];

        $response = $this->request('consumer/v3/registerurl', $payload, 'POST');

        return $this->asArray($response->json());
    }

    /**
     * Trigger a simulated C2B payment (non-production environments only).
     *
     * @return array<string, mixed>
     */
    public function simulateC2b(): array
    {
        $response = $this->request('simulate/v3/c2b/'.rawurlencode($this->consumerId), '', 'GET');

        return $this->asArray($response->json());
    }

    /**
     * Replay missed notifications for the given channel (ATP, B2C, C2B, B2B).
     *
     * @return array<string, mixed>
     */
    public function replayNotification(string $notificationType, int $limit = 100): array
    {
        $type = strtoupper($notificationType);
        $query = http_build_query([
            'notificationType' => $type,
            'id' => $this->consumerId,
            'limit' => $limit,
        ]);

        $response = $this->request('notificationReplay/v3/replayNotification?'.$query, '', 'GET');

        return $this->asArray($response->json());
    }

    // ─── OAuth ─────────────────────────────────────────────────────────

    /**
     * Get an OAuth access token (cached for ~55 minutes).
     *
     * Authorizes with HTTP Basic (consumer key:secret) and the grant
     * username/password, per T-Kash API v1.4.1.
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'billing:tkash:access_token:'.$this->consumerKey;

        /** @var string $token */
        $token = Cache::remember($cacheKey, 3300, function (): string {
            $credentials = base64_encode($this->consumerKey.':'.$this->consumerSecret);

            $response = Http::withHeaders([
                'Authorization' => 'Basic '.$credentials,
                'Accept' => 'application/json',
            ])->asForm()->post($this->baseUrl.'token?grant_type=client_credentials', [
                'username' => $this->grantUsername,
                'password' => $this->grantPassword,
            ]);

            $token = $response->json('access_token');

            return is_string($token) ? $token : '';
        });

        return $token;
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Send an authenticated request to the gateway.
     *
     * @param  array<string, mixed>|string  $payload
     */
    protected function request(string $path, array|string $payload, string $method): Response
    {
        $token = $this->getAccessToken();
        $url = $this->baseUrl.$path;

        if (is_array($payload)) {
            $this->logRequest($method, $url, $payload);
        }

        $http = Http::withToken($token)->acceptJson();

        return match (strtoupper($method)) {
            'GET' => $http->get($url),
            'PUT' => $http->put($url, is_array($payload) ? $payload : []),
            default => $http->post($url, is_array($payload) ? $payload : []),
        };
    }

    /**
     * The gateway accepts a request with HTTP 200 and a success response code.
     */
    protected function isAcceptedResponse(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $code = $this->jsonNullableString($response, 'response.responseCode')
            ?? $this->jsonNullableString($response, 'responseCode');

        // No explicit code on a 2xx is treated as accepted (request queued).
        if ($code === null) {
            return $this->jsonNullableString($response, 'errorMessage') === null;
        }

        return in_array($code, ['0', '00', '200', 'SUCCESS'], true);
    }

    /**
     * Pull the transaction node out of a C2B confirmation/validation callback.
     *
     * @return array<array-key, mixed>
     */
    protected function extractTransaction(Request $request): array
    {
        foreach (['transaction', 'confirmationRequest', 'validationRequest', 'data'] as $key) {
            $value = $request->input($key);
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        return [];
    }

    protected function resolveBaseUrl(): string
    {
        // T-Kash gateway environments: dev, uat, preprod, prod.
        $env = match (strtolower($this->environment)) {
            'production', 'prod' => 'prod',
            'preprod' => 'preprod',
            'dev' => 'dev',
            default => 'uat', // sandbox/uat
        };

        return "https://{$env}.gw.mfs-tkl.com/";
    }

    /**
     * Map a T-Kash transaction status into the package's canonical status.
     */
    protected function mapTransactionStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'SUCCESS', 'COMPLETED', 'SUCCESSFUL', '0', '00', '200' => 'completed',
            'FAILED', 'FAILURE', 'REJECTED', 'DECLINED' => 'failed',
            'CANCELLED', 'CANCELED' => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * Format phone number to 2547XXXXXXXX / 2541XXXXXXXX format.
     */
    protected function formatPhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        $phone = is_string($cleaned) ? $cleaned : $phone;

        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '254'.substr($phone, 1);
        }

        if ((str_starts_with($phone, '7') || str_starts_with($phone, '1')) && strlen($phone) === 9) {
            $phone = '254'.$phone;
        }

        return $phone;
    }
}

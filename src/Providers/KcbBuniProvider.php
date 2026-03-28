<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class KcbBuniProvider extends BasePaymentProvider
{
    protected string $apiKey;

    protected string $apiSecret;

    protected string $environment;

    protected string $callbackUrl;

    protected string $baseUrl;

    protected string $merchantCode;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $environment = 'sandbox',
        string $callbackUrl = '',
        ?string $baseUrl = null,
        string $merchantCode = '',
    ) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
        $this->merchantCode = $merchantCode;
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $reference = $options['reference'] ?? 'PAY-'.uniqid();
        $paymentChannel = $options['payment_channel'] ?? 'mpesa'; // mpesa, airtel, tkash, vooma, bank

        $payload = [
            'merchant_code' => $this->merchantCode,
            'reference' => $reference,
            'amount' => (int) ceil($amount / 100),
            'currency' => $currency ?: 'KES',
            'payment_channel' => $paymentChannel,
            'callback_url' => $options['callback_url'] ?? $this->callbackUrl,
            'description' => $options['description'] ?? 'Payment',
        ];

        if (isset($options['phone'])) {
            $payload['phone'] = $this->formatPhone($options['phone']);
        }

        if (isset($options['account_number'])) {
            $payload['account_number'] = $options['account_number'];
        }

        $this->logRequest('POST', $this->baseUrl.'/payments/initiate', $payload);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->post($this->baseUrl.'/payments/initiate', $payload);

        $data = $response->json() ?? [];

        $success = ($data['status'] ?? '') === 'success' || ($data['response_code'] ?? '') === '00';

        return [
            'success' => $success,
            'provider_payment_id' => $data['transaction_id'] ?? null,
            'provider_reference' => $data['reference'] ?? $reference,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $payload = [
            'merchant_code' => $this->merchantCode,
            'transaction_id' => $providerPaymentId,
            'amount' => $amount !== null ? (int) ceil($amount / 100) : null,
            'reason' => $options['reason'] ?? 'Refund',
        ];

        $this->logRequest('POST', $this->baseUrl.'/payments/refund', $payload);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->post($this->baseUrl.'/payments/refund', $payload);

        $data = $response->json() ?? [];

        $success = ($data['status'] ?? '') === 'success' || ($data['response_code'] ?? '') === '00';

        return [
            'success' => $success,
            'provider_refund_id' => $data['refund_id'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->get($this->baseUrl.'/payments/status/'.$providerPaymentId);

        $data = $response->json() ?? [];
        $status = $data['transaction_status'] ?? $data['status'] ?? null;

        return match ($status) {
            'completed', 'success', '00' => 'completed',
            'failed', 'error' => 'failed',
            'cancelled', 'reversed' => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        // KCB IPN supports signature verification via public key (SHA-1)
        // but many deployments rely on URL obscurity + IP whitelisting.
        // Verify the payload has a recognizable IPN structure.
        $signature = $request->header('X-KCB-Signature', $request->header('signature', ''));

        if (! empty($signature) && ! empty($this->apiSecret)) {
            $payload = $request->getContent();
            $expected = hash_hmac('sha256', $payload, $this->apiSecret);

            return hash_equals($expected, $signature);
        }

        // Fall back to structure verification for unsigned IPNs
        return $this->isV2IpnPayload($request) || $this->isV1IpnPayload($request);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        // KCB IPN V2 format (nested: header + requestPayload.additionalData.notificationData)
        if ($this->isV2IpnPayload($request)) {
            return $this->parseV2Ipn($request);
        }

        // KCB IPN V1 format (flat: transactionReference, transactionAmount, etc.)
        if ($this->isV1IpnPayload($request)) {
            return $this->parseV1Ipn($request);
        }

        // Generic fallback
        $data = $request->all();
        $amount = isset($data['amount']) ? (int) ((float) $data['amount'] * 100) : null;

        return [
            'event' => 'payment.completed',
            'provider_payment_id' => $data['transaction_id'] ?? $data['transactionID'] ?? null,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'KES',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->apiSecret);
    }

    #[\Override]
    public function getName(): string
    {
        return 'kcb';
    }

    // ─── KCB IPN V2 (Nested format) ───────────────────────────────────

    protected function isV2IpnPayload(Request $request): bool
    {
        return $request->has('header.messageID')
            || $request->has('requestPayload.additionalData.notificationData');
    }

    protected function parseV2Ipn(Request $request): array
    {
        $payload = $request->all();
        $header = $payload['header'] ?? [];
        $notificationData = $payload['requestPayload']['additionalData']['notificationData'] ?? [];
        $primaryData = $payload['requestPayload']['primaryData'] ?? [];

        $amount = isset($notificationData['transactionAmt'])
            ? (int) ((float) $notificationData['transactionAmt'] * 100)
            : null;

        return [
            'event' => 'payment.completed',
            'provider_payment_id' => $notificationData['transactionID'] ?? null,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => $notificationData['currency'] ?? 'KES',
            'metadata' => [
                'message_id' => $header['messageID'] ?? null,
                'channel_code' => $header['channelCode'] ?? null,
                'timestamp' => $header['timeStamp'] ?? null,
                'business_key' => $primaryData['businessKey'] ?? null,
                'customer_reference' => $notificationData['businessKey'] ?? null,
                'phone' => $notificationData['debitMSISDN'] ?? null,
                'first_name' => $notificationData['firstName'] ?? null,
                'last_name' => $notificationData['lastName'] ?? null,
                'narration' => $notificationData['narration'] ?? null,
                'transaction_type' => $notificationData['transactionType'] ?? null,
                'balance' => $notificationData['balance'] ?? null,
            ],
        ];
    }

    // ─── KCB IPN V1 (Flat format) ─────────────────────────────────────

    protected function isV1IpnPayload(Request $request): bool
    {
        return $request->has('transactionReference') || $request->has('customerReference');
    }

    protected function parseV1Ipn(Request $request): array
    {
        $data = $request->all();

        $amount = isset($data['transactionAmount'])
            ? (int) ((float) $data['transactionAmount'] * 100)
            : null;

        return [
            'event' => 'payment.completed',
            'provider_payment_id' => $data['transactionReference'] ?? null,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'KES',
            'metadata' => [
                'request_id' => $data['requestId'] ?? null,
                'channel_code' => $data['channelCode'] ?? null,
                'timestamp' => $data['timeStamp'] ?? null,
                'customer_reference' => $data['customerReference'] ?? null,
                'customer_name' => $data['customerName'] ?? null,
                'phone' => $data['customerMobileNumber'] ?? null,
                'balance' => $data['balance'] ?? null,
                'narration' => $data['narration'] ?? null,
                'credit_account_identifier' => $data['creditAccountIdentifier'] ?? null,
                'organization_short_code' => $data['organizationShortCode'] ?? null,
                'till_number' => $data['tillNumber'] ?? null,
            ],
        ];
    }

    // ─── KCB Validation Endpoint ───────────────────────────────────────

    /**
     * Handle KCB bill validation request.
     *
     * KCB sends a validation request before accepting payment to verify
     * the customer reference/bill number is valid. Override the callback
     * to implement your validation logic.
     *
     * @param  callable(string $customerReference, string $organizationReference): array  $validator
     *     Should return: ['valid' => bool, 'customer_name' => string, 'amount' => int (cents), 'bill_type' => string]
     */
    public function handleValidation(Request $request, callable $validator): array
    {
        $isV2 = $request->has('header.messageID');

        if ($isV2) {
            $payload = $request->all();
            $messageId = $payload['header']['messageID'] ?? '';
            $queryData = $payload['requestPayload']['additionalData']['queryData'] ?? [];
            $customerReference = $queryData['businessKey'] ?? '';
            $organizationReference = $payload['requestPayload']['primaryData']['businessKey'] ?? '';
        } else {
            $messageId = $request->input('requestId', '');
            $customerReference = $request->input('customerReference', '');
            $organizationReference = $request->input('organizationReference', '');
        }

        $result = $validator($customerReference, $organizationReference);
        $valid = $result['valid'] ?? false;
        $amountWhole = isset($result['amount']) ? number_format($result['amount'] / 100, 2, '.', '') : '';

        if ($isV2) {
            return [
                'header' => [
                    'messageID' => $messageId,
                    'statusCode' => $valid ? '0' : '1',
                    'statusMessage' => $valid ? 'Processed successfully' : 'Validation failed',
                ],
                'responsePayload' => [
                    'transactionInfo' => [
                        'transactionId' => '',
                        'customerName' => $result['customer_name'] ?? '',
                        'amount' => $amountWhole,
                        'currency' => 'KES',
                        'billType' => $result['bill_type'] ?? 'FIXED',
                    ],
                ],
            ];
        }

        return [
            'transactionID' => $messageId,
            'statusCode' => $valid ? '0' : '1',
            'statusMessage' => $valid ? 'Success' : 'Validation failed',
            'customerName' => $result['customer_name'] ?? '',
            'billAmount' => $amountWhole,
            'currency' => 'KES',
            'billType' => $result['bill_type'] ?? 'FIXED',
            'creditAccountIdentifier' => '',
        ];
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://buni.kcbgroup.com/api/v1'
            : 'https://sandbox.buni.kcbgroup.com/api/v1';
    }

    protected function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? $phone;

        if (str_starts_with($phone, '0')) {
            $phone = '254'.substr($phone, 1);
        }

        if (str_starts_with($phone, '7') && strlen($phone) === 9) {
            $phone = '254'.$phone;
        }

        return $phone;
    }
}

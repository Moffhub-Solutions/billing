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
        $reference = $this->optionString($options, 'reference', 'PAY-'.uniqid());
        $paymentChannel = $this->optionString($options, 'payment_channel', 'mpesa'); // mpesa, airtel, tkash, vooma, bank

        $payload = [
            'merchant_code' => $this->merchantCode,
            'reference' => $reference,
            'amount' => (int) ceil($amount / 100),
            'currency' => $currency !== '' ? $currency : 'KES',
            'payment_channel' => $paymentChannel,
            'callback_url' => $this->optionString($options, 'callback_url', $this->callbackUrl),
            'description' => $this->optionString($options, 'description', 'Payment'),
        ];

        $phone = $this->optionNullableString($options, 'phone');
        if ($phone !== null) {
            $payload['phone'] = $this->formatPhone($phone);
        }

        $accountNumber = $this->optionNullableString($options, 'account_number');
        if ($accountNumber !== null) {
            $payload['account_number'] = $accountNumber;
        }

        $this->logRequest('POST', $this->baseUrl.'/payments/initiate', $payload);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->post($this->baseUrl.'/payments/initiate', $payload);

        $data = $this->asArray($response->json());

        $success = ($data['status'] ?? '') === 'success' || ($data['response_code'] ?? '') === '00';

        return [
            'success' => $success,
            'provider_payment_id' => isset($data['transaction_id']) && is_string($data['transaction_id']) ? $data['transaction_id'] : null,
            'provider_reference' => isset($data['reference']) && is_string($data['reference']) ? $data['reference'] : $reference,
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
            'reason' => $this->optionString($options, 'reason', 'Refund'),
        ];

        $this->logRequest('POST', $this->baseUrl.'/payments/refund', $payload);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
        ])->post($this->baseUrl.'/payments/refund', $payload);

        $data = $this->asArray($response->json());

        $success = ($data['status'] ?? '') === 'success' || ($data['response_code'] ?? '') === '00';

        return [
            'success' => $success,
            'provider_refund_id' => isset($data['refund_id']) && is_string($data['refund_id']) ? $data['refund_id'] : null,
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

        $data = $this->asArray($response->json());
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
        // When a signing secret is configured, a valid HMAC is mandatory:
        // never fall back to a structure check (an attacker controls headers and
        // would simply omit the signature to reach the unauthenticated path).
        if ($this->apiSecret !== '') {
            $defaultSig = (string) $request->header('signature', '');
            $signature = (string) $request->header('X-KCB-Signature', $defaultSig);
            $payload = $request->getContent();
            $expected = hash_hmac('sha256', $payload, $this->apiSecret);

            return $signature !== '' && hash_equals($expected, $signature);
        }

        // No secret configured: this provider cannot authenticate the payload
        // itself. Authentication must come from the webhook URL secret / IP
        // allowlist enforced by WebhookController.
        return false;
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
        $amountValue = $data['amount'] ?? null;
        $amount = is_numeric($amountValue) ? (int) ((float) $amountValue * 100) : null;

        $providerPaymentId = $data['transaction_id'] ?? $data['transactionID'] ?? null;

        return [
            'event' => 'payment.completed',
            'provider_payment_id' => is_string($providerPaymentId) ? $providerPaymentId : null,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'KES',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
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

    /**
     * @return array{event: string, provider_payment_id: string|null, status: string, amount: int|null, currency: string, metadata: array<string, mixed>}
     */
    protected function parseV2Ipn(Request $request): array
    {
        $payload = $request->all();
        $headerRaw = $payload['header'] ?? [];
        $header = is_array($headerRaw) ? $headerRaw : [];

        $requestPayload = $payload['requestPayload'] ?? [];
        $requestPayloadArr = is_array($requestPayload) ? $requestPayload : [];

        $additionalData = $requestPayloadArr['additionalData'] ?? [];
        $additionalDataArr = is_array($additionalData) ? $additionalData : [];
        $notificationData = $additionalDataArr['notificationData'] ?? [];
        $notificationDataArr = is_array($notificationData) ? $notificationData : [];

        $primaryDataRaw = $requestPayloadArr['primaryData'] ?? [];
        $primaryData = is_array($primaryDataRaw) ? $primaryDataRaw : [];

        $txAmt = $notificationDataArr['transactionAmt'] ?? null;
        $amount = is_numeric($txAmt) ? (int) ((float) $txAmt * 100) : null;

        $providerPaymentId = $notificationDataArr['transactionID'] ?? null;

        return [
            'event' => 'payment.completed',
            'provider_payment_id' => is_string($providerPaymentId) ? $providerPaymentId : null,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => isset($notificationDataArr['currency']) && is_string($notificationDataArr['currency']) ? $notificationDataArr['currency'] : 'KES',
            'metadata' => [
                'message_id' => $header['messageID'] ?? null,
                'channel_code' => $header['channelCode'] ?? null,
                'timestamp' => $header['timeStamp'] ?? null,
                'business_key' => $primaryData['businessKey'] ?? null,
                'customer_reference' => $notificationDataArr['businessKey'] ?? null,
                'phone' => $notificationDataArr['debitMSISDN'] ?? null,
                'first_name' => $notificationDataArr['firstName'] ?? null,
                'last_name' => $notificationDataArr['lastName'] ?? null,
                'narration' => $notificationDataArr['narration'] ?? null,
                'transaction_type' => $notificationDataArr['transactionType'] ?? null,
                'balance' => $notificationDataArr['balance'] ?? null,
            ],
        ];
    }

    // ─── KCB IPN V1 (Flat format) ─────────────────────────────────────

    protected function isV1IpnPayload(Request $request): bool
    {
        return $request->has('transactionReference') || $request->has('customerReference');
    }

    /**
     * @return array{event: string, provider_payment_id: string|null, status: string, amount: int|null, currency: string, metadata: array<string, mixed>}
     */
    protected function parseV1Ipn(Request $request): array
    {
        $data = $request->all();

        $txAmount = $data['transactionAmount'] ?? null;
        $amount = is_numeric($txAmount) ? (int) ((float) $txAmount * 100) : null;

        $providerPaymentId = $data['transactionReference'] ?? null;

        return [
            'event' => 'payment.completed',
            'provider_payment_id' => is_string($providerPaymentId) ? $providerPaymentId : null,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'KES',
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
     * @param  callable(string $customerReference, string $organizationReference): array<string, mixed>  $validator
     *                                                                                                               Should return: ['valid' => bool, 'customer_name' => string, 'amount' => int (cents), 'bill_type' => string]
     * @return array<string, mixed>
     */
    public function handleValidation(Request $request, callable $validator): array
    {
        $isV2 = $request->has('header.messageID');

        if ($isV2) {
            $payload = $request->all();
            $headerRaw = $payload['header'] ?? [];
            $header = is_array($headerRaw) ? $headerRaw : [];
            $messageId = is_string($header['messageID'] ?? null) ? $header['messageID'] : '';

            $requestPayload = $payload['requestPayload'] ?? [];
            $requestPayloadArr = is_array($requestPayload) ? $requestPayload : [];
            $additionalData = $requestPayloadArr['additionalData'] ?? [];
            $additionalDataArr = is_array($additionalData) ? $additionalData : [];
            $queryData = $additionalDataArr['queryData'] ?? [];
            $queryDataArr = is_array($queryData) ? $queryData : [];
            $customerReference = is_string($queryDataArr['businessKey'] ?? null) ? $queryDataArr['businessKey'] : '';

            $primary = $requestPayloadArr['primaryData'] ?? [];
            $primaryArr = is_array($primary) ? $primary : [];
            $organizationReference = is_string($primaryArr['businessKey'] ?? null) ? $primaryArr['businessKey'] : '';
        } else {
            $messageIdRaw = $request->input('requestId', '');
            $messageId = is_string($messageIdRaw) ? $messageIdRaw : '';
            $customerReferenceRaw = $request->input('customerReference', '');
            $customerReference = is_string($customerReferenceRaw) ? $customerReferenceRaw : '';
            $organizationReferenceRaw = $request->input('organizationReference', '');
            $organizationReference = is_string($organizationReferenceRaw) ? $organizationReferenceRaw : '';
        }

        $result = $validator($customerReference, $organizationReference);
        $valid = ($result['valid'] ?? false) === true;
        $amountValue = $result['amount'] ?? null;
        $amountWhole = is_numeric($amountValue) ? number_format($amountValue / 100, 2, '.', '') : '';

        $customerName = isset($result['customer_name']) && is_string($result['customer_name']) ? $result['customer_name'] : '';
        $billType = isset($result['bill_type']) && is_string($result['bill_type']) ? $result['bill_type'] : 'FIXED';

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
                        'customerName' => $customerName,
                        'amount' => $amountWhole,
                        'currency' => 'KES',
                        'billType' => $billType,
                    ],
                ],
            ];
        }

        return [
            'transactionID' => $messageId,
            'statusCode' => $valid ? '0' : '1',
            'statusMessage' => $valid ? 'Success' : 'Validation failed',
            'customerName' => $customerName,
            'billAmount' => $amountWhole,
            'currency' => 'KES',
            'billType' => $billType,
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
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        $phone = is_string($cleaned) ? $cleaned : $phone;

        if (str_starts_with($phone, '0')) {
            $phone = '254'.substr($phone, 1);
        }

        if (str_starts_with($phone, '7') && strlen($phone) === 9) {
            $phone = '254'.$phone;
        }

        return $phone;
    }
}

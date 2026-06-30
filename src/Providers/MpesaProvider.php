<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MpesaProvider extends BasePaymentProvider
{
    protected string $consumerKey;

    protected string $consumerSecret;

    protected string $shortcode;

    protected string $passkey;

    protected string $environment;

    protected string $callbackUrl;

    protected string $timeoutUrl;

    protected string $baseUrl;

    protected ?string $initiatorName;

    protected ?string $initiatorPassword;

    protected ?string $certificatePath;

    public function __construct(
        string $consumerKey,
        string $consumerSecret,
        string $shortcode,
        string $passkey,
        string $environment = 'sandbox',
        string $callbackUrl = '',
        string $timeoutUrl = '',
        ?string $baseUrl = null,
        ?string $initiatorName = null,
        ?string $initiatorPassword = null,
        ?string $certificatePath = null,
    ) {
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->shortcode = $shortcode;
        $this->passkey = $passkey;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->timeoutUrl = $timeoutUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
        $this->initiatorName = $initiatorName;
        $this->initiatorPassword = $initiatorPassword;
        $this->certificatePath = $certificatePath;
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
                'metadata' => ['error' => 'Phone number is required for M-Pesa STK Push.'],
            ];
        }

        return $this->stkPush(
            phone: $this->formatPhone($phone),
            amount: (int) ceil($amount / 100), // convert cents to whole KES
            accountReference: $this->optionString($options, 'account_reference', 'Payment'),
            transactionDesc: $this->optionString($options, 'description', 'Payment'),
            callbackUrl: $this->optionString($options, 'callback_url', $this->callbackUrl),
        );
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
                'metadata' => ['error' => 'Phone number is required for M-Pesa B2C refund.'],
            ];
        }

        return $this->b2c(
            phone: $this->formatPhone($phone),
            amount: $amount !== null ? (int) ceil($amount / 100) : 0,
            remarks: $this->optionString($options, 'remarks', 'Refund'),
            occasion: $this->optionString($options, 'occasion', "Refund for {$providerPaymentId}"),
        );
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $result = $this->stkQuery($providerPaymentId);
        $resultCode = $result['ResultCode'] ?? '-1';

        return match (true) {
            $resultCode === '0' || $resultCode === 0 => 'completed',
            $resultCode === '1032' || $resultCode === 1032 => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        // Safaricom does not sign Daraja STK/result callbacks, so the payload is
        // not self-authenticating. Report "unverified": WebhookController confirms
        // the real outcome via an async STK status re-query (and optional URL
        // secret / IP allowlist) before settling, so a forged callback settles
        // nothing.
        return false;
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        // STK Push callback
        $stkCallback = $request->input('Body.stkCallback');
        if (is_array($stkCallback)) {
            return $this->parseStkCallback($stkCallback);
        }

        // B2C / Transaction Status callback
        $result = $request->input('Result');
        if (is_array($result)) {
            return $this->parseResultCallback($result);
        }

        // C2B validation/confirmation
        $transId = $request->input('TransID');
        if ($transId !== null) {
            return $this->parseC2bCallback($request->all());
        }

        return [
            'event' => 'unknown',
            'provider_payment_id' => null,
            'status' => 'unknown',
            'amount' => null,
            'currency' => 'KES',
            'metadata' => $request->all(),
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return $this->consumerKey !== ''
            && $this->consumerSecret !== ''
            && $this->shortcode !== ''
            && $this->passkey !== '';
    }

    #[\Override]
    public function getName(): string
    {
        return 'mpesa';
    }

    // ─── STK Push (Lipa na M-Pesa Online) ──────────────────────────────

    /**
     * Initiate an STK Push to the customer's phone.
     *
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function stkPush(
        string $phone,
        int $amount,
        string $accountReference = 'Payment',
        string $transactionDesc = 'Payment',
        ?string $callbackUrl = null,
    ): array {
        $token = $this->getAccessToken();
        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->shortcode.$this->passkey.$timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl ?? $this->callbackUrl,
            'AccountReference' => substr($accountReference, 0, 12),
            'TransactionDesc' => substr($transactionDesc, 0, 13),
        ];

        $this->logRequest('POST', $this->baseUrl.'/mpesa/stkpush/v1/processrequest', $payload);

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/mpesa/stkpush/v1/processrequest', $payload);

        $data = $this->asArray($response->json());
        $success = ($data['ResponseCode'] ?? '') === '0';

        return [
            'success' => $success,
            'provider_payment_id' => isset($data['CheckoutRequestID']) && is_string($data['CheckoutRequestID']) ? $data['CheckoutRequestID'] : null,
            'provider_reference' => isset($data['MerchantRequestID']) && is_string($data['MerchantRequestID']) ? $data['MerchantRequestID'] : null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    /**
     * Query the status of an STK Push request.
     *
     * @return array<string, mixed>
     */
    public function stkQuery(string $checkoutRequestId): array
    {
        $token = $this->getAccessToken();
        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->shortcode.$this->passkey.$timestamp);

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $this->shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);

        return $this->asArray($response->json());
    }

    // ─── C2B (Customer to Business) ────────────────────────────────────

    /**
     * Register C2B validation and confirmation URLs with Safaricom.
     *
     * @return array<string, mixed>
     */
    public function registerC2bUrls(string $confirmationUrl, string $validationUrl, string $responseType = 'Completed'): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/mpesa/c2b/v1/registerurl', [
                'ShortCode' => $this->shortcode,
                'ResponseType' => $responseType,
                'ConfirmationURL' => $confirmationUrl,
                'ValidationURL' => $validationUrl,
            ]);

        return $this->asArray($response->json());
    }

    /**
     * Simulate a C2B payment (sandbox only).
     *
     * Triggers a fake customer-to-business payment for testing.
     * This calls the validation → confirmation flow just like a real paybill payment.
     *
     * @return array{success: bool, metadata: array<string, mixed>}
     */
    public function simulateC2b(
        string $phone,
        int $amount,
        string $billRefNumber = '',
        string $commandId = 'CustomerPayBillOnline',
    ): array {
        $token = $this->getAccessToken();

        $payload = [
            'ShortCode' => $this->shortcode,
            'CommandID' => $commandId, // CustomerPayBillOnline or CustomerBuyGoodsOnline
            'Amount' => $amount,
            'Msisdn' => $this->formatPhone($phone),
            'BillRefNumber' => $billRefNumber,
        ];

        $this->logRequest('POST', $this->baseUrl.'/mpesa/c2b/v1/simulate', $payload);

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/mpesa/c2b/v1/simulate', $payload);

        $data = $this->asArray($response->json());

        return [
            'success' => ($data['ResponseCode'] ?? '') === '0',
            'metadata' => $data,
        ];
    }

    /**
     * Handle C2B validation callback.
     *
     * Safaricom sends a validation request before accepting a C2B payment.
     * Pass a validator callable to accept or reject the payment.
     *
     * @param  callable(array<array-key, mixed> $data): bool  $validator
     *                                                                    Receives the full C2B payload. Return true to accept, false to reject.
     * @return array{ResultCode: string, ResultDesc: string} Response to send back to Safaricom
     */
    public function handleC2bValidation(Request $request, callable $validator): array
    {
        $data = $request->all();
        $accepted = $validator($data);

        return [
            'ResultCode' => $accepted ? '0' : 'C2B00012',
            'ResultDesc' => $accepted ? 'Accepted' : 'Rejected',
        ];
    }

    // ─── B2C (Business to Customer — refunds/disbursements) ────────────

    /**
     * Send money from business to customer (refunds, disbursements).
     *
     * @return array{success: bool, provider_refund_id: string|null, status: string, metadata: array<string, mixed>}
     */
    public function b2c(
        string $phone,
        int $amount,
        string $commandId = 'BusinessPayment',
        string $remarks = 'Payment',
        string $occasion = '',
    ): array {
        if ($this->initiatorName === null || $this->initiatorPassword === null) {
            return [
                'success' => false,
                'provider_refund_id' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'B2C requires initiator credentials.'],
            ];
        }

        $token = $this->getAccessToken();
        $securityCredential = $this->generateSecurityCredential();

        $payload = [
            'InitiatorName' => $this->initiatorName,
            'SecurityCredential' => $securityCredential,
            'CommandID' => $commandId,
            'Amount' => $amount,
            'PartyA' => $this->shortcode,
            'PartyB' => $phone,
            'Remarks' => substr($remarks, 0, 100),
            'QueueTimeOutURL' => $this->timeoutUrl,
            'ResultURL' => $this->callbackUrl,
            'Occassion' => substr($occasion, 0, 100), // Safaricom's actual spelling
        ];

        $this->logRequest('POST', $this->baseUrl.'/mpesa/b2c/v1/paymentrequest', $payload);

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/mpesa/b2c/v1/paymentrequest', $payload);

        $data = $this->asArray($response->json());
        $success = ($data['ResponseCode'] ?? '') === '0';

        return [
            'success' => $success,
            'provider_refund_id' => isset($data['ConversationID']) && is_string($data['ConversationID']) ? $data['ConversationID'] : null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    // ─── OAuth ─────────────────────────────────────────────────────────

    /**
     * Get an OAuth access token (cached for ~55 minutes).
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'billing:mpesa:access_token:'.$this->shortcode;

        /** @var string $token */
        $token = Cache::remember($cacheKey, 3300, function (): string {
            $credentials = base64_encode($this->consumerKey.':'.$this->consumerSecret);

            $response = Http::withHeaders([
                'Authorization' => 'Basic '.$credentials,
            ])->get($this->baseUrl.'/oauth/v1/generate', [
                'grant_type' => 'client_credentials',
            ]);

            $token = $response->json('access_token');

            return is_string($token) ? $token : '';
        });

        return $token;
    }

    // ─── Callback Parsing ──────────────────────────────────────────────

    /**
     * @param  array<array-key, mixed>  $callback
     * @return array{event: string, provider_payment_id: string|null, status: string, amount: int|null, currency: string, metadata: array<string, mixed>}
     */
    protected function parseStkCallback(array $callback): array
    {
        $resultCode = $callback['ResultCode'] ?? -1;
        $success = $resultCode === 0 || $resultCode === '0';
        $metadata = [];

        $callbackMeta = $callback['CallbackMetadata'] ?? null;
        $items = is_array($callbackMeta) && isset($callbackMeta['Item']) && is_array($callbackMeta['Item'])
            ? $callbackMeta['Item']
            : [];

        if ($success) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = $item['Name'] ?? null;
                if (is_string($name)) {
                    $metadata[$name] = $item['Value'] ?? null;
                }
            }
        }

        $amountValue = $metadata['Amount'] ?? null;
        $amount = is_numeric($amountValue) ? (int) ($amountValue * 100) : null;

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => isset($callback['CheckoutRequestID']) && is_string($callback['CheckoutRequestID']) ? $callback['CheckoutRequestID'] : null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => $amount,
            'currency' => 'KES',
            'metadata' => [
                'merchant_request_id' => $callback['MerchantRequestID'] ?? null,
                'result_code' => $resultCode,
                'result_desc' => $callback['ResultDesc'] ?? null,
                'mpesa_receipt' => $metadata['MpesaReceiptNumber'] ?? null,
                'phone' => isset($metadata['PhoneNumber']) && (is_string($metadata['PhoneNumber']) || is_int($metadata['PhoneNumber'])) ? (string) $metadata['PhoneNumber'] : null,
                'transaction_date' => $metadata['TransactionDate'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $result
     * @return array{event: string, provider_payment_id: string|null, status: string, amount: int|null, currency: string, metadata: array<string, mixed>}
     */
    protected function parseResultCallback(array $result): array
    {
        $resultCode = $result['ResultCode'] ?? -1;
        $success = $resultCode === 0 || $resultCode === '0';
        $params = [];

        $resultParams = $result['ResultParameters'] ?? null;
        $paramList = is_array($resultParams) && isset($resultParams['ResultParameter']) && is_array($resultParams['ResultParameter'])
            ? $resultParams['ResultParameter']
            : [];

        foreach ($paramList as $param) {
            if (! is_array($param)) {
                continue;
            }
            $key = $param['Key'] ?? null;
            if (is_string($key)) {
                $params[$key] = $param['Value'] ?? null;
            }
        }

        $amountValue = $params['TransactionAmount'] ?? null;
        $amount = is_numeric($amountValue) ? (int) ($amountValue * 100) : null;

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => isset($result['ConversationID']) && is_string($result['ConversationID']) ? $result['ConversationID'] : null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => $amount,
            'currency' => 'KES',
            'metadata' => [
                'transaction_id' => $result['TransactionID'] ?? null,
                'result_code' => $resultCode,
                'result_desc' => $result['ResultDesc'] ?? null,
                'receipt' => $params['TransactionReceipt'] ?? null,
                ...$params,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{event: string, provider_payment_id: string|null, status: string, amount: int|null, currency: string, metadata: array<string, mixed>}
     */
    protected function parseC2bCallback(array $data): array
    {
        $transAmount = $data['TransAmount'] ?? null;
        $amount = is_numeric($transAmount) ? (int) (((float) $transAmount) * 100) : null;

        return [
            'event' => 'payment.completed',
            'provider_payment_id' => isset($data['TransID']) && is_string($data['TransID']) ? $data['TransID'] : null,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => 'KES',
            'metadata' => [
                'transaction_type' => $data['TransactionType'] ?? null,
                'phone' => $data['MSISDN'] ?? null,
                'bill_ref_number' => $data['BillRefNumber'] ?? null,
                'first_name' => $data['FirstName'] ?? null,
                'last_name' => $data['LastName'] ?? null,
                'org_balance' => $data['OrgAccountBalance'] ?? null,
                'trans_time' => $data['TransTime'] ?? null,
            ],
        ];
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Format phone number to 2547XXXXXXXX format.
     */
    protected function formatPhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        $phone = is_string($cleaned) ? $cleaned : $phone;

        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }

        if (str_starts_with($phone, '0')) {
            $phone = '254'.substr($phone, 1);
        }

        if (str_starts_with($phone, '7') && strlen($phone) === 9) {
            $phone = '254'.$phone;
        }

        return $phone;
    }

    /**
     * Generate SecurityCredential for B2C and Transaction Status APIs.
     */
    protected function generateSecurityCredential(): string
    {
        if ($this->certificatePath !== null && file_exists($this->certificatePath)) {
            $publicKey = file_get_contents($this->certificatePath);
        } else {
            // Use default sandbox certificate
            $publicKey = $this->getDefaultCertificate();
        }

        if (! is_string($publicKey)) {
            return '';
        }

        $encrypted = '';
        openssl_public_encrypt($this->initiatorPassword ?? '', $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);

        return base64_encode($encrypted);
    }

    /**
     * Default Safaricom sandbox certificate public key.
     */
    protected function getDefaultCertificate(): string
    {
        // Safaricom sandbox certificate — in production, use the production cert from Daraja portal
        return <<<'CERT'
-----BEGIN CERTIFICATE-----
MIIGkzCCBXugAwIBAgIKXfBp5gAAAD+hNjANBgkqhkiG9w0BAQsFADBbMRMwEQYK
CZImiZPyLGQBGRYDbmV0MRkwFwYKCZImiZPyLGQBGRYJc2FmYXJpY29tMSkwJwYD
VQQDEyBTYWZhcmljb20gSW50ZXJuYWwgSXNzdWluZyBDQSAwMjAeFw0xNzA0MjUx
NjA3MjdaFw0xODA0MjUxNjA3MjdaMIGNMQswCQYDVQQGEwJLRTEQMA4GA1UECBMH
TmFpcm9iaTEQMA4GA1UEBxMHTmFpcm9iaTEaMBgGA1UEChMRU2FmYXJpY29tIExp
bWl0ZWQxEzARBgNVBAsTClRlY2hub2xvZ3kxKTAnBgNVBAMTIGFwaWdlZS5hcGlj
YWxsZXIuc2FmYXJpY29tLmNvLmtlMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIB
CgKCAQEAoknIb5Tm1hxOVdFsOejAs6veAai32k3bAVSp9DXPANS0pRi8/PS2JBkb
mN1F5fMGZKMbSE4jyhmgySgeJGsUPUWNcWoXSMbCJBCVxNro118VG1bJ8KBwRrfT
bP0gr/SjwMeDIGaByEIFN0eRI6k/ihQdYmbBejJHMDLcoKbXkOqYJW3sCQY+bsFi
h44PoyEMpKN/tMv7zmUYNBZJPSxCjsHVNkmBDAjCcUQQmr8gSTsJFpKtMRfb8Ld/
OQM0R/FLfAUDoUmQVRQwXDmVkjUp/W7heDNVQ9X6cERSgIviYSfFO+BTXcKkrCoY
Y+p5B9pyRgx/gQP5GOFnGBVK+DBRXQIDAQAB
-----END CERTIFICATE-----
CERT;
    }
}

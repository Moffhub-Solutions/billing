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
        $phone = $options['phone'] ?? null;

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
            accountReference: $options['account_reference'] ?? 'Payment',
            transactionDesc: $options['description'] ?? 'Payment',
            callbackUrl: $options['callback_url'] ?? $this->callbackUrl,
        );
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $phone = $options['phone'] ?? null;

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
            remarks: $options['remarks'] ?? 'Refund',
            occasion: $options['occasion'] ?? "Refund for {$providerPaymentId}",
        );
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $result = $this->stkQuery($providerPaymentId);

        return match ($result['ResultCode'] ?? '-1') {
            '0', 0 => 'completed',
            '1032', 1032 => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        // M-Pesa callbacks don't have signature verification.
        // Security is via callback URL obscurity + IP whitelisting at infrastructure level.
        // Verify the payload structure is valid.
        $body = $request->input('Body.stkCallback') ?? $request->input('Result');

        return $body !== null;
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        // STK Push callback
        $stkCallback = $request->input('Body.stkCallback');
        if ($stkCallback !== null) {
            return $this->parseStkCallback($stkCallback);
        }

        // B2C / Transaction Status callback
        $result = $request->input('Result');
        if ($result !== null) {
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
        return ! empty($this->consumerKey)
            && ! empty($this->consumerSecret)
            && ! empty($this->shortcode)
            && ! empty($this->passkey);
    }

    #[\Override]
    public function getName(): string
    {
        return 'mpesa';
    }

    // ─── STK Push (Lipa na M-Pesa Online) ──────────────────────────────

    /**
     * Initiate an STK Push to the customer's phone.
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

        $data = $response->json();

        $success = ($data['ResponseCode'] ?? '') === '0';

        return [
            'success' => $success,
            'provider_payment_id' => $data['CheckoutRequestID'] ?? null,
            'provider_reference' => $data['MerchantRequestID'] ?? null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    /**
     * Query the status of an STK Push request.
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

        return $response->json() ?? [];
    }

    // ─── C2B (Customer to Business) ────────────────────────────────────

    /**
     * Register C2B validation and confirmation URLs with Safaricom.
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

        return $response->json() ?? [];
    }

    // ─── B2C (Business to Customer — refunds/disbursements) ────────────

    /**
     * Send money from business to customer (refunds, disbursements).
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

        $data = $response->json();
        $success = ($data['ResponseCode'] ?? '') === '0';

        return [
            'success' => $success,
            'provider_refund_id' => $data['ConversationID'] ?? null,
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

        return Cache::remember($cacheKey, 3300, function (): string {
            $credentials = base64_encode($this->consumerKey.':'.$this->consumerSecret);

            $response = Http::withHeaders([
                'Authorization' => 'Basic '.$credentials,
            ])->get($this->baseUrl.'/oauth/v1/generate', [
                'grant_type' => 'client_credentials',
            ]);

            return $response->json('access_token') ?? '';
        });
    }

    // ─── Callback Parsing ──────────────────────────────────────────────

    protected function parseStkCallback(array $callback): array
    {
        $resultCode = $callback['ResultCode'] ?? -1;
        $success = $resultCode === 0 || $resultCode === '0';
        $metadata = [];

        if ($success && isset($callback['CallbackMetadata']['Item'])) {
            foreach ($callback['CallbackMetadata']['Item'] as $item) {
                $metadata[$item['Name']] = $item['Value'] ?? null;
            }
        }

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => $callback['CheckoutRequestID'] ?? null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => isset($metadata['Amount']) ? (int) ($metadata['Amount'] * 100) : null,
            'currency' => 'KES',
            'metadata' => [
                'merchant_request_id' => $callback['MerchantRequestID'] ?? null,
                'result_code' => $resultCode,
                'result_desc' => $callback['ResultDesc'] ?? null,
                'mpesa_receipt' => $metadata['MpesaReceiptNumber'] ?? null,
                'phone' => isset($metadata['PhoneNumber']) ? (string) $metadata['PhoneNumber'] : null,
                'transaction_date' => $metadata['TransactionDate'] ?? null,
            ],
        ];
    }

    protected function parseResultCallback(array $result): array
    {
        $resultCode = $result['ResultCode'] ?? -1;
        $success = $resultCode === 0 || $resultCode === '0';
        $params = [];

        if (isset($result['ResultParameters']['ResultParameter'])) {
            foreach ($result['ResultParameters']['ResultParameter'] as $param) {
                $params[$param['Key']] = $param['Value'] ?? null;
            }
        }

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => $result['ConversationID'] ?? null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => isset($params['TransactionAmount']) ? (int) ($params['TransactionAmount'] * 100) : null,
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

    protected function parseC2bCallback(array $data): array
    {
        $amount = isset($data['TransAmount']) ? (int) (((float) $data['TransAmount']) * 100) : null;

        return [
            'event' => 'payment.completed',
            'provider_payment_id' => $data['TransID'] ?? null,
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
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? $phone;

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

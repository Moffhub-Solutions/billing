<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CoopBankProvider extends BasePaymentProvider
{
    protected string $consumerKey;

    protected string $consumerSecret;

    protected string $accountNumber;

    protected string $environment;

    protected string $callbackUrl;

    protected string $baseUrl;

    public function __construct(
        string $consumerKey,
        string $consumerSecret,
        string $accountNumber = '',
        string $environment = 'sandbox',
        string $callbackUrl = '',
        ?string $baseUrl = null,
    ) {
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->accountNumber = $accountNumber;
        $this->environment = $environment;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $baseUrl ?? $this->resolveBaseUrl();
    }

    // ─── PaymentProviderInterface ──────────────────────────────────────

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $reference = $this->optionString($options, 'reference', 'PAY-'.uniqid());
        $token = $this->getAccessToken();
        $amountWhole = number_format($amount / 100, 2, '.', '');

        // Determine transfer type: PesaLink (to other banks) or internal
        $transferType = $this->optionString($options, 'transfer_type', 'pesalink');

        $payload = [
            'MessageReference' => $reference,
            'AccountNumber' => $this->optionString($options, 'destination_account'),
            'Amount' => $amountWhole,
            'TransactionCurrency' => $currency !== '' ? $currency : 'KES',
            'Narration' => $this->optionString($options, 'description', 'Payment'),
            'CallBackUrl' => $this->optionString($options, 'callback_url', $this->callbackUrl),
        ];

        if ($transferType === 'pesalink') {
            $payload['BankCode'] = $this->optionString($options, 'bank_code');
            $endpoint = '/FundsTransfer/External/A2A/PesaLink';
        } else {
            $payload['SourceAccountNumber'] = $this->accountNumber;
            $endpoint = '/FundsTransfer/Internal/A2A';
        }

        $phone = $this->optionNullableString($options, 'phone');
        if ($phone !== null) {
            $payload['PhoneNumber'] = $this->formatPhone($phone);
        }

        $this->logRequest('POST', $this->baseUrl.$endpoint, $payload);

        $response = Http::withToken($token)
            ->post($this->baseUrl.$endpoint, $payload);

        $data = $this->asArray($response->json());

        $success = ($data['MessageCode'] ?? '') === '0' || ($data['status'] ?? '') === 'success';

        $providerPaymentId = isset($data['MessageReference']) && is_string($data['MessageReference'])
            ? $data['MessageReference']
            : $reference;

        return [
            'success' => $success,
            'provider_payment_id' => $providerPaymentId,
            'provider_reference' => isset($data['TransactionReference']) && is_string($data['TransactionReference']) ? $data['TransactionReference'] : null,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        $reference = $this->optionString($options, 'reference', 'REF-'.uniqid());
        $token = $this->getAccessToken();
        $amountWhole = $amount !== null ? number_format($amount / 100, 2, '.', '') : '0.00';

        $payload = [
            'MessageReference' => $reference,
            'SourceAccountNumber' => $this->accountNumber,
            'AccountNumber' => $this->optionString($options, 'destination_account'),
            'Amount' => $amountWhole,
            'TransactionCurrency' => 'KES',
            'Narration' => $this->optionString($options, 'description', "Refund for {$providerPaymentId}"),
            'CallBackUrl' => $this->optionString($options, 'callback_url', $this->callbackUrl),
        ];

        $this->logRequest('POST', $this->baseUrl.'/FundsTransfer/Internal/A2A', $payload);

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/FundsTransfer/Internal/A2A', $payload);

        $data = $this->asArray($response->json());

        $success = ($data['MessageCode'] ?? '') === '0' || ($data['status'] ?? '') === 'success';

        $providerRefundId = isset($data['MessageReference']) && is_string($data['MessageReference'])
            ? $data['MessageReference']
            : $reference;

        return [
            'success' => $success,
            'provider_refund_id' => $providerRefundId,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/Enquiry/TransactionStatus', [
                'MessageReference' => $providerPaymentId,
            ]);

        $data = $this->asArray($response->json());
        $status = $data['TransactionStatus'] ?? $data['MessageCode'] ?? null;

        return match ($status) {
            'completed', 'success', '0' => 'completed',
            'failed', 'error', '-1' => 'failed',
            'reversed', 'cancelled' => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        // Co-op Connect callbacks are not signed (security is IP-allowlist +
        // URL secrecy per Co-op's docs), so the payload is not self-authenticating.
        // Report "unverified": WebhookController confirms via the Transaction
        // Status Enquiry re-query before settling.
        return false;
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $data = $request->all();
        $messageCode = $data['MessageCode'] ?? $data['message_code'] ?? null;
        $success = $messageCode === '0' || ($data['status'] ?? '') === 'success';

        $amountUpper = $data['Amount'] ?? null;
        $amountLower = $data['amount'] ?? null;

        $amount = is_numeric($amountUpper)
            ? (int) ((float) $amountUpper * 100)
            : (is_numeric($amountLower) ? (int) ((float) $amountLower * 100) : null);

        $providerPaymentId = $data['MessageReference'] ?? $data['message_reference'] ?? null;
        $currency = $data['TransactionCurrency'] ?? $data['currency'] ?? 'KES';

        return [
            'event' => $success ? 'payment.completed' : 'payment.failed',
            'provider_payment_id' => is_string($providerPaymentId) ? $providerPaymentId : null,
            'status' => $success ? 'completed' : 'failed',
            'amount' => $amount,
            'currency' => is_string($currency) ? $currency : 'KES',
            'metadata' => [
                'transaction_reference' => $data['TransactionReference'] ?? $data['transaction_reference'] ?? null,
                'message_description' => $data['MessageDescription'] ?? $data['message_description'] ?? null,
                'source_account' => $data['SourceAccountNumber'] ?? null,
                'destination_account' => $data['AccountNumber'] ?? null,
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
        return 'coopbank';
    }

    // ─── OAuth ─────────────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        $cacheKey = 'billing:coopbank:access_token:'.$this->consumerKey;

        /** @var string $token */
        $token = Cache::remember($cacheKey, 3300, function (): string {
            $credentials = base64_encode($this->consumerKey.':'.$this->consumerSecret);

            $response = Http::withHeaders([
                'Authorization' => 'Basic '.$credentials,
            ])->post($this->baseUrl.'/token', [
                'grant_type' => 'client_credentials',
            ]);

            $accessToken = $response->json('access_token');

            return is_string($accessToken) ? $accessToken : '';
        });

        return $token;
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function getAccountBalance(?string $accountNumber = null): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post($this->baseUrl.'/Enquiry/AccountBalance', [
                'MessageReference' => 'BAL-'.uniqid(),
                'AccountNumber' => $accountNumber ?? $this->accountNumber,
            ]);

        return $this->asArray($response->json());
    }

    protected function resolveBaseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://developer.co-opbank.co.ke/api/v1'
            : 'https://developer.co-opbank.co.ke/sandbox/api/v1';
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

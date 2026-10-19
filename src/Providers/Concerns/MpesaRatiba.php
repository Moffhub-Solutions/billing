<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Enums\StandingOrderFrequency;

/**
 * M-Pesa Ratiba (Safaricom Daraja standing orders) for MpesaProvider.
 *
 * Reuses the host class's OAuth token, base URL, shortcode and phone formatting.
 *
 * VERIFY AGAINST SAFARICOM DOCS BEFORE GO-LIVE: the create endpoint and request
 * fields follow the public Daraja documentation. The cancel and status
 * endpoints, the transaction type strings and the callback payload shape are
 * not confirmed publicly, so they are configurable and parsed permissively.
 *
 * @property string $shortcode
 * @property string $baseUrl
 * @property string $callbackUrl
 * @property array<string, mixed> $ratibaConfig
 */
trait MpesaRatiba
{
    public function ratibaEnabled(): bool
    {
        return (bool) ($this->ratibaConfig['enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_standing_order_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function createStandingOrder(array $options): array
    {
        if (! $this->ratibaEnabled()) {
            return $this->ratibaFailure('Ratiba is not enabled for this deployment');
        }

        $type = $this->ratibaString($options['transaction_type'] ?? null)
            ?? $this->ratibaString($this->ratibaConfig['transaction_type'] ?? null)
            ?? 'paybill';

        if (! in_array($type, ['paybill', 'till'], true)) {
            throw new \InvalidArgumentException("Ratiba transaction_type must be 'paybill' or 'till'.");
        }

        $name = $this->ratibaString($options['name'] ?? null);
        if ($name === null) {
            throw new \InvalidArgumentException('A standing order name is required.');
        }

        $cents = $options['amount'] ?? null;
        if (! is_int($cents) || $cents <= 0) {
            throw new \InvalidArgumentException('Standing order amount (cents) must be a positive integer.');
        }

        $phone = $this->formatPhone($this->ratibaString($options['phone'] ?? null) ?? '');
        if (preg_match('/^254[17]\d{8}$/', $phone) !== 1) {
            throw new \InvalidArgumentException('A valid Kenyan phone number is required (2547XXXXXXXX).');
        }

        $frequency = $this->resolveFrequency($options['frequency'] ?? ($this->ratibaConfig['default_frequency'] ?? 'monthly'));

        $start = $this->parseRatibaDate($options['start_date'] ?? now(), 'start_date');
        $end = isset($options['end_date']) ? $this->parseRatibaDate($options['end_date'], 'end_date') : $start->addYears(10);

        if ($end->lessThan($start)) {
            throw new \InvalidArgumentException('end_date must not be before start_date.');
        }

        $accountReference = $this->ratibaString($options['account_reference'] ?? null) ?? 'Subscription';
        $callback = $this->ratibaString($options['callback_url'] ?? null)
            ?? $this->ratibaString($this->ratibaConfig['callback_url'] ?? null);

        if ($callback === null) {
            throw new \InvalidArgumentException('A Ratiba callback URL is required (MPESA_RATIBA_CALLBACK_URL).');
        }

        $identifierTypes = $this->ratibaMap('receiver_party_identifier_types', ['paybill' => '4', 'till' => '2']);
        $transactionTypes = $this->ratibaMap('transaction_types', [
            'paybill' => 'Standing Order Customer Pay Bill',
            'till' => 'Standing Order Customer Pay Merchant',
        ]);

        $payload = [
            'StandingOrderName' => $name,
            'StartDate' => $start->format('Ymd'),
            'EndDate' => $end->format('Ymd'),
            'BusinessShortCode' => $this->shortcode,
            'TransactionType' => $transactionTypes[$type] ?? $transactionTypes['paybill'],
            'ReceiverPartyIdentifierType' => $identifierTypes[$type] ?? '4',
            'Amount' => (int) ceil($cents / 100), // cents to whole KES, as for STK Push
            'PartyA' => $phone,
            'CallBackURL' => $callback,
            'AccountReference' => substr((string) preg_replace('/[^A-Za-z0-9]/', '', $accountReference), 0, 12),
            'TransactionDesc' => substr($this->ratibaString($options['description'] ?? null) ?? 'Subscription', 0, 13),
            'Frequency' => $frequency->safaricomCode(),
        ];

        $url = $this->baseUrl.$this->ratibaPath('create', '/standingorder/v1/createStandingOrderExternal');
        $this->logRequest('POST', $url, $payload);

        $response = Http::withToken($this->getAccessToken())->post($url, $payload);
        $data = $this->asArray($response->json());

        $code = $this->ratibaResponseCode($data);
        $success = $response->successful() && in_array($code, ['0', '00', '200'], true);
        $ref = $this->ratibaFirst($data, ['ResponseHeader.responseRefID', 'responseRefID', 'ResponseRefID']);

        return [
            'success' => $success,
            'provider_standing_order_id' => $this->ratibaFirst($data, ['ResponseBody.standingOrderID', 'standingOrderID', 'StandingOrderID']) ?? $ref,
            'provider_reference' => $ref,
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, status: string, metadata: array<string, mixed>}
     */
    public function cancelStandingOrder(string $id, array $options = []): array
    {
        if (! $this->ratibaEnabled()) {
            return $this->ratibaFailure('Ratiba is not enabled for this deployment', false);
        }

        $path = $this->ratibaPath('cancel', null);
        if ($path === null) {
            return $this->ratibaFailure('No Ratiba cancel endpoint is configured (billing.providers.mpesa.ratiba.endpoints.cancel). The customer can cancel from their M-Pesa menu.', false);
        }

        $payload = ['StandingOrderID' => $id, 'BusinessShortCode' => $this->shortcode, ...$options];
        $this->logRequest('POST', $this->baseUrl.$path, $payload);

        $response = Http::withToken($this->getAccessToken())->post($this->baseUrl.$path, $payload);
        $data = $this->asArray($response->json());
        $success = $response->successful() && in_array($this->ratibaResponseCode($data), ['0', '00', '200'], true);

        return ['success' => $success, 'status' => $success ? 'cancelled' : 'failed', 'metadata' => $data];
    }

    /**
     * @return array{success: bool, status: string, metadata: array<string, mixed>}
     */
    public function getStandingOrderStatus(string $id): array
    {
        if (! $this->ratibaEnabled()) {
            return $this->ratibaFailure('Ratiba is not enabled for this deployment', false);
        }

        $path = $this->ratibaPath('status', null);
        if ($path === null) {
            return $this->ratibaFailure('No Ratiba status endpoint is configured (billing.providers.mpesa.ratiba.endpoints.status).', false);
        }

        $payload = ['StandingOrderID' => $id, 'BusinessShortCode' => $this->shortcode];
        $response = Http::withToken($this->getAccessToken())->post($this->baseUrl.$path, $payload);
        $data = $this->asArray($response->json());
        $success = $response->successful() && in_array($this->ratibaResponseCode($data), ['0', '00', '200'], true);
        $status = strtolower($this->ratibaFirst($data, ['ResponseBody.Status', 'ResponseBody.status', 'Status', 'status']) ?? 'unknown');

        return ['success' => $success, 'status' => $status, 'metadata' => $data];
    }

    /**
     * Normalise a Ratiba callback (or a paybill C2B confirmation that a standing
     * order debit produced) into a flat structure. The service decides what the
     * result means based on the order's current state.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{provider_reference: string|null, request_reference: string|null, account_reference: string|null, standing_order_id: string|null, transaction_id: string|null, amount: int|null, success: bool, result_code: string|null, description: string|null, status: string|null, raw: array<array-key, mixed>}
     */
    public function parseStandingOrderCallback(array $payload): array
    {
        $c2b = isset($payload['TransID']) && is_string($payload['TransID']);

        $code = $c2b ? '0' : $this->ratibaFirst($payload, [
            'ResponseBody.responseCode', 'ResponseBody.ResponseCode', 'responseCode', 'ResponseCode', 'ResponseHeader.responseCode', 'ResultCode',
        ]);

        $amountRaw = $c2b
            ? ($payload['TransAmount'] ?? null)
            : $this->ratibaFirst($payload, ['ResponseBody.Amount', 'ResponseBody.amount', 'Amount']);

        return [
            'provider_reference' => $this->ratibaFirst($payload, ['ResponseHeader.responseRefID', 'responseRefID']),
            'request_reference' => $this->ratibaFirst($payload, ['ResponseHeader.requestRefID', 'requestRefID']),
            'account_reference' => $c2b ? $this->ratibaFirst($payload, ['BillRefNumber']) : $this->ratibaFirst($payload, ['ResponseBody.AccountReference', 'AccountReference']),
            'standing_order_id' => $this->ratibaFirst($payload, ['ResponseBody.standingOrderID', 'ResponseBody.StandingOrderID', 'StandingOrderID', 'standingOrderID']),
            'transaction_id' => $c2b ? $this->ratibaFirst($payload, ['TransID']) : $this->ratibaFirst($payload, ['ResponseBody.TransactionID', 'ResponseBody.transactionID', 'TransactionID']),
            'amount' => is_numeric($amountRaw) ? (int) round(((float) $amountRaw) * 100) : null,
            'success' => in_array($code, ['0', '00', '200'], true),
            'result_code' => $code,
            'description' => $this->ratibaFirst($payload, ['ResponseBody.responseDescription', 'ResponseHeader.responseDescription', 'responseDescription', 'ResultDesc']),
            'status' => $this->ratibaFirst($payload, ['ResponseBody.Status', 'ResponseBody.status', 'Status']),
            'raw' => $payload,
        ];
    }

    protected function resolveFrequency(mixed $value): StandingOrderFrequency
    {
        if ($value instanceof StandingOrderFrequency) {
            return $value;
        }

        if (is_string($value)) {
            $normalised = strtolower(str_replace(['-', ' '], '_', $value));
            $found = StandingOrderFrequency::tryFrom($normalised);
            if ($found !== null) {
                return $found;
            }
        }

        throw new \InvalidArgumentException('Unknown standing order frequency.');
    }

    protected function parseRatibaDate(mixed $value, string $field): CarbonImmutable
    {
        try {
            if ($value instanceof \DateTimeInterface) {
                return CarbonImmutable::instance($value)->startOfDay();
            }

            if (is_string($value) && $value !== '') {
                return CarbonImmutable::parse($value)->startOfDay();
            }
        } catch (\Throwable) {
            // fall through
        }

        throw new \InvalidArgumentException("Invalid {$field}.");
    }

    protected function ratibaString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, string>  $default
     * @return array<string, string>
     */
    protected function ratibaMap(string $key, array $default): array
    {
        $configured = $this->ratibaConfig[$key] ?? null;
        $map = $default;

        if (is_array($configured)) {
            foreach ($configured as $k => $v) {
                if (is_string($k) && (is_string($v) || is_int($v))) {
                    $map[$k] = (string) $v;
                }
            }
        }

        return $map;
    }

    protected function ratibaPath(string $name, ?string $default): ?string
    {
        $endpoints = $this->ratibaConfig['endpoints'] ?? [];
        $value = is_array($endpoints) ? ($endpoints[$name] ?? null) : null;
        $path = is_string($value) && $value !== '' ? $value : $default;

        return $path === null ? null : '/'.ltrim($path, '/');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function ratibaResponseCode(array $data): ?string
    {
        return $this->ratibaFirst($data, [
            'ResponseHeader.responseCode', 'responseCode', 'ResponseCode', 'ResponseBody.responseCode',
        ]);
    }

    /**
     * First scalar found at any of the dot paths, as a string.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $paths
     */
    protected function ratibaFirst(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @return ($withIds is true ? array{success: bool, provider_standing_order_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>} : array{success: bool, status: string, metadata: array<string, mixed>})
     */
    protected function ratibaFailure(string $message, bool $withIds = true): array
    {
        if ($withIds) {
            return [
                'success' => false,
                'provider_standing_order_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => $message],
            ];
        }

        return ['success' => false, 'status' => 'failed', 'metadata' => ['error' => $message]];
    }
}

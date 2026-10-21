<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Exceptions\PaystackException;

/**
 * Paystack subaccount, transaction split and bank management.
 *
 * Every failure (missing key, dropped connection, `status: false`) surfaces as a
 * PaystackException carrying Paystack's own message. Each method returns the
 * decoded `data` object. Account numbers go on the wire only and are never
 * logged.
 */
class PaystackSplitApi
{
    public function __construct(
        protected string $secretKey,
        protected string $baseUrl = 'https://api.paystack.co',
    ) {}

    /**
     * @return list<array<mixed, mixed>>
     */
    public function listBanks(string $country = 'kenya', int $perPage = 200): array
    {
        $data = $this->send('get', '/bank', ['country' => $country, 'perPage' => $perPage]);

        return array_values(array_filter($this->rows($data), is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $payload  business_name, settlement_bank, account_number, percentage_charge, ...
     * @return array<string, mixed>
     */
    public function createSubaccount(array $payload): array
    {
        return $this->object($this->send('post', '/subaccount', $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateSubaccount(string $idOrCode, array $payload): array
    {
        return $this->object($this->send('put', '/subaccount/'.$idOrCode, $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSubaccount(string $idOrCode): array
    {
        return $this->object($this->send('get', '/subaccount/'.$idOrCode));
    }

    /**
     * @param  array<string, mixed>  $payload  name, type, currency, subaccounts[{subaccount, share}], bearer_type, ...
     * @return array<string, mixed>
     */
    public function createSplit(array $payload): array
    {
        return $this->object($this->send('post', '/split', $payload));
    }

    /**
     * @param  array<string, mixed>  $payload  name, active, bearer_type, bearer_subaccount
     * @return array<string, mixed>
     */
    public function updateSplit(string $idOrCode, array $payload): array
    {
        return $this->object($this->send('put', '/split/'.$idOrCode, $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSplit(string $idOrCode): array
    {
        return $this->object($this->send('get', '/split/'.$idOrCode));
    }

    /**
     * @return array<string, mixed>
     */
    public function addOrUpdateSplitSubaccount(string $idOrCode, string $subaccount, float|int $share): array
    {
        return $this->object($this->send('post', '/split/'.$idOrCode.'/subaccount/add', [
            'subaccount' => $subaccount,
            'share' => $share,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function removeSplitSubaccount(string $idOrCode, string $subaccount): array
    {
        return $this->object($this->send('post', '/split/'.$idOrCode.'/subaccount/remove', [
            'subaccount' => $subaccount,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $payload = []): array
    {
        if ($this->secretKey === '') {
            throw new PaystackException('Paystack is not configured. Set the Paystack secret key first.');
        }

        try {
            $response = $this->request()->{$method}(rtrim($this->baseUrl, '/').$path, $payload);
        } catch (ConnectionException) {
            throw new PaystackException('Could not reach Paystack. Please try again shortly.');
        }

        $json = $response->json();
        $data = is_array($json) ? $json : [];

        if (($data['status'] ?? false) !== true) {
            $message = is_string($data['message'] ?? null) && $data['message'] !== ''
                ? $data['message']
                : 'Paystack rejected the request (HTTP '.$response->status().').';

            throw new PaystackException($message);
        }

        return $data;
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 250, fn (\Throwable $exception): bool => $exception instanceof ConnectionException, throw: false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function object(array $data): array
    {
        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function rows(array $data): array
    {
        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }
}

<?php

declare(strict_types=1);

namespace Moffhub\Billing\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaystackProvider extends BasePaymentProvider
{
    /**
     * Resolves split options for a charge that supplies none. Receives the
     * charge currency and returns split options (e.g. ['split_code' => ...]) or
     * null. Lets an app keep its split in its own storage.
     *
     * @var (\Closure(string): (array<string, mixed>|null))|null
     */
    protected static ?\Closure $defaultSplitResolver = null;

    /**
     * @param  (\Closure(string): (array<string, mixed>|null))|null  $resolver
     */
    public static function resolveDefaultSplitUsing(?\Closure $resolver): void
    {
        static::$defaultSplitResolver = $resolver;
    }

    public function __construct(
        protected string $secretKey,
        protected string $publicKey = '',
        protected string $webhookSecret = '',
        protected string $baseUrl = 'https://api.paystack.co',
    ) {}

    #[\Override]
    public function charge(int $amount, string $currency, array $options = []): array
    {
        $email = $this->optionNullableString($options, 'email');

        if ($email === null) {
            return [
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Email is required for Paystack.'],
            ];
        }

        // If authorization_code is provided, charge the saved card
        $authCode = $this->optionNullableString($options, 'authorization_code');

        if ($authCode !== null) {
            return $this->chargeAuthorization($email, $amount, $currency, $authCode, $options);
        }

        // Otherwise, initialize a new transaction (returns redirect URL)
        return $this->initializeTransaction($email, $amount, $currency, $options);
    }

    #[\Override]
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
    {
        /** @var array<string, mixed> $payload */
        $payload = ['transaction' => $providerPaymentId];

        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        $reason = $this->optionNullableString($options, 'reason');

        if ($reason !== null) {
            $payload['merchant_note'] = $reason;
        }

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/refund', $payload);

        $data = $this->asArray($response->json());
        $statusFlag = $data['status'] ?? false;
        $success = $statusFlag === true;

        $metadataData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        return [
            'success' => $success,
            'provider_refund_id' => $this->jsonNullableString($response, 'data.transaction.id'),
            'status' => $success ? 'pending' : 'failed',
            'metadata' => $this->stringKeyed($metadataData),
        ];
    }

    #[\Override]
    public function getPaymentStatus(string $providerPaymentId): string
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl.'/transaction/verify/'.$providerPaymentId);

        return match ($this->jsonString($response, 'data.status', 'unknown')) {
            'success' => 'completed',
            'failed' => 'failed',
            'abandoned' => 'cancelled',
            default => 'pending',
        };
    }

    #[\Override]
    public function verifyWebhook(Request $request): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }

        $signature = (string) $request->header('x-paystack-signature', '');
        $payload = $request->getContent();
        $expected = hash_hmac('sha512', $payload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    #[\Override]
    public function parseWebhook(Request $request): array
    {
        $eventRaw = $request->input('event', '');
        $event = is_string($eventRaw) ? $eventRaw : '';

        $dataRaw = $request->input('data', []);
        $data = is_array($dataRaw) ? $dataRaw : [];

        $status = match ($event) {
            'charge.success' => 'completed',
            'charge.failed' => 'failed',
            'refund.processed' => 'refunded',
            default => 'pending',
        };

        $plan = is_array($data['plan'] ?? null) ? $data['plan'] : [];
        $subscription = is_array($data['subscription'] ?? null) ? $data['subscription'] : [];
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $subscriptionCode = $data['subscription_code'] ?? $subscription['subscription_code'] ?? null;
        $planCode = $plan['plan_code'] ?? null;
        $customerEmail = $customer['email'] ?? null;
        $transactionMeta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return [
            'event' => match ($event) {
                'charge.success' => 'payment.completed',
                'charge.failed' => 'payment.failed',
                'refund.processed' => 'payment.refunded',
                'subscription.create' => 'subscription.created',
                'subscription.disable' => 'subscription.disabled',
                'subscription.not_renew' => 'subscription.not_renewing',
                'invoice.payment_failed' => 'subscription.payment_failed',
                default => $event,
            },
            'provider_payment_id' => isset($data['reference']) && is_string($data['reference']) ? $data['reference'] : null,
            'status' => $status,
            'amount' => isset($data['amount']) ? $this->asNullableInt($data['amount']) : null, // Paystack amounts are already in kobo/cents
            'currency' => isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null,
            'metadata' => [
                'paystack_event' => $event,
                'channel' => $data['channel'] ?? null,
                'authorization' => $data['authorization'] ?? null,
                'customer' => $data['customer'] ?? null,
                // What the subscription handler needs. Paystack does not
                // document these payloads, so every field is read defensively.
                'subscription' => [
                    'subscription_code' => is_string($subscriptionCode) ? $subscriptionCode : null,
                    'email_token' => is_string($data['email_token'] ?? null) ? $data['email_token'] : null,
                    'plan_code' => is_string($planCode) ? $planCode : null,
                    'customer_email' => is_string($customerEmail) ? strtolower($customerEmail) : null,
                    'next_payment_date' => is_string($data['next_payment_date'] ?? null) ? $data['next_payment_date'] : null,
                    'status' => is_string($data['status'] ?? null) ? $data['status'] : null,
                    'transaction_metadata' => $transactionMeta,
                ],
            ],
        ];
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    #[\Override]
    public function getName(): string
    {
        return 'paystack';
    }

    // ─── Paystack-specific methods ─────────────────────────────────────

    /**
     * Initialize a transaction (returns a redirect URL for the customer).
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function initializeTransaction(string $email, int $amount, string $currency, array $options = []): array
    {
        $payload = [
            'email' => $email,
            'amount' => $amount, // in kobo/cents
            'currency' => $currency,
            'reference' => $this->optionNullableString($options, 'reference'),
            'callback_url' => $this->optionNullableString($options, 'callback_url'),
            'metadata' => $this->optionArray($options, 'metadata') ?: null,
            // A plan code makes this the first charge of a subscription: the
            // plan's amount replaces `amount` and Paystack bills every cycle.
            'plan' => $this->optionNullableString($options, 'plan'),
            // Limits the checkout page to these channels (e.g. ['card']).
            'channels' => $this->optionArray($options, 'channels') ?: null,
        ];

        // Merge after array_filter so a dynamic split array (and a zero
        // transaction_charge) is not dropped by the truthiness filter.
        $body = array_merge(array_filter($payload), $this->splitOptions($options, $currency));

        $this->logRequest('POST', $this->baseUrl.'/transaction/initialize', $body);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/transaction/initialize', $body);

        $data = $this->asArray($response->json());
        $statusFlag = $data['status'] ?? false;
        $success = $statusFlag === true;

        $inner = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];

        return [
            'success' => $success,
            'provider_payment_id' => $this->jsonNullableString($response, 'data.reference'),
            'provider_reference' => $this->jsonNullableString($response, 'data.access_code'),
            'status' => $success ? 'pending' : 'failed',
            'metadata' => [
                'authorization_url' => $this->jsonNullableString($response, 'data.authorization_url'),
                ...$inner,
            ],
        ];
    }

    /**
     * Charge a saved authorization (recurring payment).
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, provider_payment_id: string|null, provider_reference: string|null, status: string, metadata: array<string, mixed>}
     */
    public function chargeAuthorization(string $email, int $amount, string $currency, string $authorizationCode, array $options = []): array
    {
        $payload = [
            'email' => $email,
            'amount' => $amount,
            'currency' => $currency,
            'authorization_code' => $authorizationCode,
            'reference' => $this->optionNullableString($options, 'reference'),
        ];

        $body = array_merge(array_filter($payload), $this->splitOptions($options, $currency));

        $this->logRequest('POST', $this->baseUrl.'/transaction/charge_authorization', $body);

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/transaction/charge_authorization', $body);

        $data = $this->asArray($response->json());
        $statusFlag = $data['status'] ?? false;
        $innerStatus = $this->jsonString($response, 'data.status', '');
        $success = $statusFlag === true && $innerStatus === 'success';

        $metadataData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        return [
            'success' => $success,
            'provider_payment_id' => $this->jsonNullableString($response, 'data.reference'),
            'provider_reference' => $this->jsonNullableString($response, 'data.id'),
            'status' => $success ? 'completed' : 'failed',
            'metadata' => $this->stringKeyed($metadataData),
        ];
    }

    /**
     * Paystack split-payment / subaccount routing parameters, read from the
     * charge options. Supports a saved split (`split_code`), a one-off dynamic
     * `split` object, or a single `subaccount` plus optional `bearer` and flat
     * `transaction_charge` (in kobo/cents). Only the keys actually supplied are
     * returned, so the rest of the payload is untouched.
     *
     * SECURITY: these route a share of the payment to another account, so the
     * values must be supplied by trusted server-side code, never forwarded from
     * an untrusted payer. The HTTP charge endpoint strips them from client input
     * (see PaymentController::SERVER_ONLY_OPTION_KEYS).
     *
     * When the charge supplies none of these, the app's default split applies
     * (resolveDefaultSplitUsing, else `split_code` config) for a matching currency.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function splitOptions(array $options, ?string $currency = null): array
    {
        $split = [];

        if (($subaccount = $this->optionNullableString($options, 'subaccount')) !== null) {
            $split['subaccount'] = $subaccount;
        }

        if (($splitCode = $this->optionNullableString($options, 'split_code')) !== null) {
            $split['split_code'] = $splitCode;
        }

        $bearer = $this->optionNullableString($options, 'bearer');
        if ($bearer === 'account' || $bearer === 'subaccount') {
            $split['bearer'] = $bearer;
        }

        // Only meaningful alongside a subaccount; 0 is a valid "no flat charge".
        if (isset($split['subaccount']) && is_numeric($options['transaction_charge'] ?? null)) {
            $split['transaction_charge'] = (int) $options['transaction_charge'];
        }

        $dynamic = $this->optionArray($options, 'split');
        if ($dynamic !== []) {
            $split['split'] = $dynamic;
        }

        if ($split === [] && $currency !== null) {
            return $this->defaultSplit($currency);
        }

        return $split;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultSplit(string $currency): array
    {
        if (static::$defaultSplitResolver !== null) {
            return (static::$defaultSplitResolver)($currency) ?? [];
        }

        $code = config('billing.providers.paystack.split_code');
        $splitCurrency = config('billing.providers.paystack.split_currency');

        if (! is_string($code) || $code === '') {
            return [];
        }

        if (is_string($splitCurrency) && $splitCurrency !== '' && strcasecmp($splitCurrency, $currency) !== 0) {
            return [];
        }

        return ['split_code' => $code];
    }

    // ─── Plans and subscriptions (cards; Paystack bills each cycle) ────

    /**
     * Create a plan on Paystack.
     *
     * @return array{success: bool, plan_code: string|null, message: string|null}
     */
    public function createPlan(string $name, int $amount, string $interval, string $currency, ?string $description = null): array
    {
        $body = array_filter([
            'name' => $name,
            'amount' => $amount,
            'interval' => $interval,
            'currency' => $currency,
            'description' => $description,
        ], static fn (mixed $value): bool => $value !== null);

        $response = Http::withToken($this->secretKey)->post($this->baseUrl.'/plan', $body);

        return [
            'success' => ($this->asArray($response->json())['status'] ?? false) === true,
            'plan_code' => $this->jsonNullableString($response, 'data.plan_code'),
            'message' => $this->jsonNullableString($response, 'message'),
        ];
    }

    /**
     * Update a plan. `$updateExisting` also applies the change to the plan's
     * live subscriptions, so it is off unless the caller asks for it.
     *
     * @return array{success: bool, message: string|null}
     */
    public function updatePlan(string $planCode, string $name, int $amount, string $interval, ?string $description = null, bool $updateExisting = false): array
    {
        $body = array_filter([
            'name' => $name,
            'amount' => $amount,
            'interval' => $interval,
            'description' => $description,
            'update_existing_subscriptions' => $updateExisting,
        ], static fn (mixed $value): bool => $value !== null);

        $response = Http::withToken($this->secretKey)->put($this->baseUrl.'/plan/'.$planCode, $body);

        return [
            'success' => ($this->asArray($response->json())['status'] ?? false) === true,
            'message' => $this->jsonNullableString($response, 'message'),
        ];
    }

    /**
     * Subscribe a customer who already has a saved card.
     *
     * @return array{success: bool, subscription_code: string|null, email_token: string|null, message: string|null}
     */
    public function createSubscription(string $customer, string $planCode, ?string $authorization = null, ?string $startDate = null): array
    {
        $body = array_filter([
            'customer' => $customer,
            'plan' => $planCode,
            'authorization' => $authorization,
            'start_date' => $startDate,
        ], static fn (mixed $value): bool => $value !== null);

        $response = Http::withToken($this->secretKey)->post($this->baseUrl.'/subscription', $body);

        return [
            'success' => ($this->asArray($response->json())['status'] ?? false) === true,
            'subscription_code' => $this->jsonNullableString($response, 'data.subscription_code'),
            'email_token' => $this->jsonNullableString($response, 'data.email_token'),
            'message' => $this->jsonNullableString($response, 'message'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSubscription(string $subscriptionCode): array
    {
        $response = Http::withToken($this->secretKey)->get($this->baseUrl.'/subscription/'.$subscriptionCode);
        $data = $this->asArray($response->json());

        return isset($data['data']) && is_array($data['data']) ? $this->stringKeyed($data['data']) : [];
    }

    /**
     * Stop Paystack charging a subscription. Needs the `email_token` Paystack
     * returned when the subscription was created.
     */
    public function disableSubscription(string $subscriptionCode, string $emailToken): bool
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/subscription/disable', ['code' => $subscriptionCode, 'token' => $emailToken]);

        return ($this->asArray($response->json())['status'] ?? false) === true;
    }

    public function enableSubscription(string $subscriptionCode, string $emailToken): bool
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl.'/subscription/enable', ['code' => $subscriptionCode, 'token' => $emailToken]);

        return ($this->asArray($response->json())['status'] ?? false) === true;
    }

    /**
     * A hosted page where the customer updates the card or cancels.
     */
    public function subscriptionManageLink(string $subscriptionCode): ?string
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl.'/subscription/'.$subscriptionCode.'/manage/link');

        return $this->jsonNullableString($response, 'data.link');
    }
}

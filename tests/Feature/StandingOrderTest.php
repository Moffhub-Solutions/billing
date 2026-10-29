<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\StandingOrderStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Events\StandingOrderActivated;
use Moffhub\Billing\Events\StandingOrderCancelled;
use Moffhub\Billing\Events\StandingOrderExecuted;
use Moffhub\Billing\Events\StandingOrderFailed;
use Moffhub\Billing\Exceptions\StandingOrderException;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\StandingOrder;
use Moffhub\Billing\Models\StandingOrderExecution;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\StandingOrderService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class StandingOrderTest extends BaseTestCase
{
    protected Company $company;

    protected Subscription $subscription;

    protected string $ref = 'ref-1';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setConfig('billing.providers.mpesa', [
            'consumer_key' => 'k', 'consumer_secret' => 's', 'shortcode' => '600000', 'passkey' => 'p',
            'environment' => 'sandbox',
            'ratiba' => [
                'enabled' => true,
                'callback_url' => 'https://example.com/billing/webhooks/mpesa/ratiba',
                'callback_secret' => 'topsecret',
                'endpoints' => ['create' => '/standingorder/v1/createStandingOrderExternal', 'cancel' => '/so/cancel', 'status' => null],
                'max_failures' => 2,
            ],
        ]);

        $plan = Plan::factory()->create(['base_price' => 500000, 'billing_cycle' => BillingCycle::MONTHLY, 'currency' => 'KES']);
        $this->company = Company::create(['name' => 'Acme']);
        $this->subscription = Subscription::factory()->create([
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'plan_id' => $plan->id,
            'current_period_end' => now()->addDays(5),
        ]);
    }

    protected function fakeDaraja(string $ref = 'ref-1'): void
    {
        $this->ref = $ref; // stubs read this lazily because the first registered stub wins
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/createStandingOrderExternal' => fn () => Http::response(['ResponseHeader' => ['responseRefID' => $this->ref, 'responseCode' => '200']]),
            '*/so/cancel' => Http::response(['ResponseHeader' => ['responseCode' => '200']]),
        ]);
    }

    protected function service(): StandingOrderService
    {
        return $this->app()->make(StandingOrderService::class);
    }

    protected function createOrder(): StandingOrder
    {
        $this->fakeDaraja();

        return $this->service()->createForSubscription($this->subscription, '0712345678', [
            'consent' => true, 'consent_ip' => '10.0.0.1', 'consent_user_agent' => 'Test/1.0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function activationPayload(string $ref = 'ref-1'): array
    {
        return [
            'ResponseHeader' => ['responseRefID' => $ref, 'requestRefID' => 'q'],
            'ResponseBody' => ['responseCode' => '0', 'TransactionID' => 'ACT1', 'Status' => 'Active'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function debit(string $txn, string $amount = '5000.00'): array
    {
        return ['TransID' => $txn, 'TransAmount' => $amount, 'BillRefNumber' => $this->lastOrder()->account_reference];
    }

    protected function lastOrder(): StandingOrder
    {
        return StandingOrder::query()->latest('id')->firstOrFail();
    }

    public function test_create_records_consent_phone_and_pending_status(): void
    {
        $order = $this->createOrder();

        $this->assertSame(StandingOrderStatus::Pending, $order->status);
        $this->assertSame(500000, $order->amount);
        $this->assertSame('monthly', $order->frequency->value);
        $this->assertSame('ref-1', $order->provider_reference);
        $this->assertSame('254712345678', $order->customer_phone);
        $this->assertSame('5678', $order->customer_phone_last4);
        $this->assertSame('10.0.0.1', $order->consent_ip);
        $this->assertNotNull($order->consent_at);
        $this->assertArrayNotHasKey('customer_phone', $order->toArray());
    }

    public function test_consent_is_required(): void
    {
        $this->fakeDaraja();
        $this->expectException(StandingOrderException::class);

        $this->service()->createForSubscription($this->subscription, '0712345678');
    }

    public function test_second_open_order_for_a_subscription_is_refused(): void
    {
        $this->createOrder();
        $this->expectException(StandingOrderException::class);

        $this->createOrder();
    }

    public function test_provider_rejection_creates_failed_order_and_event(): void
    {
        Event::fake([StandingOrderFailed::class]);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/createStandingOrderExternal' => Http::response(['ResponseHeader' => ['responseCode' => '500']], 500),
        ]);

        $order = $this->service()->createForSubscription($this->subscription, '0712345678', ['consent' => true]);

        $this->assertSame(StandingOrderStatus::Failed, $order->status);
        Event::assertDispatched(StandingOrderFailed::class);
    }

    public function test_amount_resolver_hook(): void
    {
        StandingOrderService::resolveAmountUsing(fn (Subscription $s): int => 650000);
        try {
            $this->assertSame(650000, $this->createOrder()->amount);
        } finally {
            StandingOrderService::resolveAmountUsing(null);
        }
    }

    public function test_activation_callback(): void
    {
        Event::fake([StandingOrderActivated::class]);
        $this->createOrder();

        $result = $this->service()->handleCallback($this->activationPayload());

        $this->assertSame('activated', $result['event']);
        $order = $this->lastOrder();
        $this->assertSame(StandingOrderStatus::Active, $order->status);
        $this->assertNotNull($order->next_expected_at);
        $this->assertSame(0, StandingOrderExecution::query()->count());
        Event::assertDispatched(StandingOrderActivated::class);
    }

    public function test_declined_approval_marks_failed(): void
    {
        Event::fake([StandingOrderFailed::class]);
        $this->createOrder();

        $this->service()->handleCallback([
            'ResponseHeader' => ['responseRefID' => 'ref-1'],
            'ResponseBody' => ['responseCode' => '1037', 'responseDescription' => 'Timeout'],
        ]);

        $this->assertSame(StandingOrderStatus::Failed, $this->lastOrder()->status);
        Event::assertDispatched(StandingOrderFailed::class);
    }

    public function test_execution_records_payment_extends_subscription_and_is_idempotent(): void
    {
        $this->createOrder();
        $this->service()->handleCallback($this->activationPayload());
        Event::fake([StandingOrderExecuted::class, PaymentReceived::class]);
        $before = $this->subscription->refresh()->current_period_end;

        $first = $this->service()->handleCallback($this->debit('TXN1'));
        $second = $this->service()->handleCallback($this->debit('TXN1'));

        $this->assertSame('executed', $first['event']);
        $this->assertSame('duplicate', $second['event']);
        $this->assertSame(1, StandingOrderExecution::query()->count());
        $this->assertSame(1, Payment::query()->where('provider_payment_id', 'TXN1')->count());
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertSame(500000, $payment->amount);
        $this->assertNotNull($before);
        $this->assertTrue($this->subscription->refresh()->current_period_end?->greaterThan($before) ?? false);
        $this->assertNotNull($this->lastOrder()->last_executed_at);
        Event::assertDispatchedTimes(StandingOrderExecuted::class, 1);
        Event::assertDispatchedTimes(PaymentReceived::class, 1);
    }

    public function test_failed_debits_count_and_fire_events_until_order_fails(): void
    {
        $this->createOrder();
        $this->service()->handleCallback($this->activationPayload());
        $this->subscription->update(['current_period_end' => now()->subDay()]);
        Event::fake([StandingOrderFailed::class, PaymentFailed::class]);

        $fail = fn (): array => $this->service()->handleCallback([
            'ResponseHeader' => ['responseRefID' => 'ref-1'],
            'ResponseBody' => ['responseCode' => '2001', 'responseDescription' => 'Insufficient funds'],
        ]);

        $fail();
        $this->assertSame(1, $this->lastOrder()->failure_count);
        $this->assertSame(StandingOrderStatus::Active, $this->lastOrder()->status);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $this->subscription->refresh()->status);

        $fail();
        $this->assertSame(StandingOrderStatus::Failed, $this->lastOrder()->status);
        Event::assertDispatchedTimes(StandingOrderFailed::class, 2);
        Event::assertDispatchedTimes(PaymentFailed::class, 2);
    }

    public function test_success_resets_failure_count(): void
    {
        $order = $this->createOrder();
        $this->service()->handleCallback($this->activationPayload());
        $order->refresh()->update(['failure_count' => 1]);

        $this->service()->handleCallback($this->debit('TXN9'));

        $this->assertSame(0, $this->lastOrder()->failure_count);
    }

    public function test_customer_cancellation_callback(): void
    {
        Event::fake([StandingOrderCancelled::class]);
        $this->createOrder();
        $this->service()->handleCallback($this->activationPayload());

        $this->service()->handleCallback([
            'ResponseHeader' => ['responseRefID' => 'ref-1'],
            'ResponseBody' => ['responseCode' => '0', 'Status' => 'Cancelled'],
        ]);

        $this->assertSame(StandingOrderStatus::Cancelled, $this->lastOrder()->status);
        Event::assertDispatched(StandingOrderCancelled::class);
    }

    public function test_unknown_callback_is_not_handled(): void
    {
        $this->fakeDaraja();

        $this->assertFalse($this->service()->handleCallback($this->activationPayload('nope'))['handled']);
    }

    public function test_cancel_calls_provider_and_requires_endpoint_unless_forced(): void
    {
        $order = $this->createOrder();
        $cancelled = $this->service()->cancel($order, 'user_request');

        $this->assertSame(StandingOrderStatus::Cancelled, $cancelled->status);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/so/cancel'));

        $this->setConfig('billing.providers.mpesa.ratiba.endpoints.cancel', null);
        $this->app()->make(PaymentManager::class)->forgetDrivers();
        $second = StandingOrder::factory()->create([
            'billable_type' => Company::class, 'billable_id' => $this->company->id, 'status' => StandingOrderStatus::Active,
        ]);

        try {
            $this->service()->cancel($second);
            $this->fail('Expected exception');
        } catch (StandingOrderException) {
            $this->assertSame(StandingOrderStatus::Active, $second->refresh()->status);
        }

        $forced = $this->service()->cancel($second, 'forced', true);
        $this->assertSame(StandingOrderStatus::Cancelled, $forced->status);
        $this->assertTrue(data_get($forced->metadata, 'provider_cancel_pending'));
    }

    public function test_change_amount_cancels_old_and_returns_new_pending_order(): void
    {
        $old = $this->createOrder();
        $this->service()->handleCallback($this->activationPayload());
        $this->fakeDaraja('ref-2');

        $new = $this->service()->changeAmount($old->refresh(), 900000);

        $this->assertSame(StandingOrderStatus::Pending, $new->status);
        $this->assertSame(900000, $new->amount);
        $this->assertSame('ref-2', $new->provider_reference);
        $this->assertSame('254712345678', $new->customer_phone);
        $this->assertSame($old->subscription_id, $new->subscription_id);
        $this->assertSame(StandingOrderStatus::Cancelled, $old->refresh()->status);
        $this->assertSame($old->ulid, data_get($new->metadata, 'replaces'));
        $this->assertNotSame($old->name, $new->name);
    }

    public function test_change_amount_same_amount_is_a_noop(): void
    {
        $old = $this->createOrder();

        $this->assertTrue($this->service()->changeAmount($old, 500000)->is($old));
    }

    public function test_reconcile_expires_stale_pending_and_flags_missed_debits(): void
    {
        Event::fake([StandingOrderFailed::class]);
        $this->fakeDaraja();

        $stale = StandingOrder::factory()->create(['status' => StandingOrderStatus::Pending, 'provider_reference' => null, 'created_at' => now()->subDays(3)]);
        $ended = StandingOrder::factory()->active()->create(['end_date' => now()->subDay()->toDateString(), 'provider_reference' => null]);
        $missed = StandingOrder::factory()->active()->create(['next_expected_at' => now()->subDays(10), 'provider_reference' => null]);
        $fresh = StandingOrder::factory()->active()->create(['provider_reference' => null]);

        $this->billingArtisan('billing:reconcile-standing-orders')->assertSuccessful();

        $this->assertSame(StandingOrderStatus::Expired, $stale->refresh()->status);
        $this->assertSame(StandingOrderStatus::Expired, $ended->refresh()->status);
        $this->assertSame(1, $missed->refresh()->failure_count);
        $this->assertSame(0, $fresh->refresh()->failure_count);
        Event::assertDispatchedTimes(StandingOrderFailed::class, 1);
    }

    public function test_webhook_route_requires_secret_and_enabled(): void
    {
        $this->createOrder();
        $url = '/billing/webhooks/mpesa/ratiba';

        $this->postJson($url, $this->activationPayload())->assertForbidden();
        $this->postJson($url.'?secret=wrong', $this->activationPayload())->assertForbidden();
        $this->assertSame(StandingOrderStatus::Pending, $this->lastOrder()->status);

        $this->postJson($url.'?secret=topsecret', $this->activationPayload())->assertOk();
        $this->assertSame(StandingOrderStatus::Active, $this->lastOrder()->status);

        $this->setConfig('billing.providers.mpesa.ratiba.enabled', false);
        $this->postJson($url.'?secret=topsecret', $this->activationPayload())->assertNotFound();
    }

    public function test_webhook_accepts_allowlisted_ip_without_secret(): void
    {
        $this->setConfig('billing.providers.mpesa.ratiba.callback_secret', null);
        $this->setConfig('billing.providers.mpesa.ratiba.ip_allowlist', ['127.0.0.1']);
        $this->createOrder();

        $this->postJson('/billing/webhooks/mpesa/ratiba', $this->activationPayload())->assertOk();
    }

    public function test_a_paybill_order_sends_the_fields_safaricom_documents(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/createStandingOrderExternal' => Http::response([
                'responseHeader' => ['responseRefID' => 'ref-doc', 'responseCode' => '200'],
                'ResponseBody' => ['responseDescription' => 'Request accepted for processing', 'responseCode' => '200'],
            ]),
        ]);

        $order = $this->service()->createForSubscription($this->subscription, '0712345678', [
            'consent' => true, 'account_reference' => 'ACME-1234-5678-9', 'description' => 'Starter plan monthly',
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/createStandingOrderExternal')) {
                return true;
            }

            return $request['TransactionType'] === 'Standing Order Customer Pay Bill'
                && $request['ReceiverPartyIdentifierType'] === '4'
                && $request['BusinessShortCode'] === '600000'
                && $request['Amount'] === '5000'
                && $request['Frequency'] === '4'
                && $request['PartyA'] === '254712345678'
                && preg_match('/^[A-Za-z0-9]{1,12}$/', $request['AccountReference']) === 1
                && strlen($request['TransactionDesc']) <= 13
                && preg_match('/^\d{8}$/', $request['StartDate']) === 1;
        });
        $this->assertSame('ref-doc', $order->provider_reference, 'the reference is read from the documented lower case header');
        $this->assertSame(StandingOrderStatus::Pending, $order->status);
    }

    public function test_the_documented_callbacks_activate_and_fail_an_order(): void
    {
        Event::fake([StandingOrderActivated::class, StandingOrderFailed::class]);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/createStandingOrderExternal' => Http::response(['responseHeader' => ['responseRefID' => 'ref-doc', 'responseCode' => '200']]),
        ]);
        $order = $this->service()->createForSubscription($this->subscription, '0712345678', ['consent' => true]);

        $ok = $this->service()->handleCallback([
            'responseHeader' => ['responseRefID' => 'ref-doc', 'responseCode' => '0'],
            'ResponseBody' => ['ResponseData' => ['TransactionID' => 'SC8F2IQMH5', 'responseCode' => '0', 'Status' => 'OKAY', 'Msisdn' => '254******867']],
        ]);

        $this->assertSame('activated', $ok['event']);
        $this->assertSame(StandingOrderStatus::Active, $order->refresh()->status);

        $other = $this->service()->createForSubscription($this->subscription, '0712345678', ['consent' => true, 'allow_multiple' => true]);
        $other->provider_reference = 'ref-fail';
        $other->save();
        $bad = $this->service()->handleCallback([
            'responseHeader' => ['responseRefID' => 'ref-fail', 'responseCode' => '1037', 'responseDescription' => 'Error'],
            'ResponseBody' => ['ResponseData' => ['TransactionID' => '0000000000', 'responseCode' => '1037', 'Status' => 'ERROR']],
        ]);

        $this->assertSame('failed', $bad['event']);
        $this->assertSame(StandingOrderStatus::Failed, $other->refresh()->status);
    }

    public function test_a_ratiba_debit_on_the_paybill_confirmation_url_is_recorded_against_its_order(): void
    {
        $this->setConfig('billing.webhooks.providers.mpesa.secret', 'c2bsecret');
        $this->createOrder();
        $this->service()->handleCallback($this->activationPayload());

        $this->postJson('/billing/webhooks/mpesa?secret=c2bsecret', $this->debit('TXNRATIBA1'))->assertOk();

        $this->assertSame(1, StandingOrderExecution::query()->count());
        $this->assertSame(1, Payment::query()->where('provider_payment_id', 'TXNRATIBA1')->count());

        // The same confirmation delivered again is not recorded twice.
        $this->postJson('/billing/webhooks/mpesa?secret=c2bsecret', $this->debit('TXNRATIBA1'))->assertOk();
        $this->assertSame(1, StandingOrderExecution::query()->count());
    }

    public function test_an_ordinary_paybill_payment_is_not_taken_for_a_standing_order_debit(): void
    {
        $this->setConfig('billing.webhooks.providers.mpesa.secret', 'c2bsecret');
        $this->createOrder();
        $this->service()->handleCallback($this->activationPayload());

        $this->postJson('/billing/webhooks/mpesa?secret=c2bsecret', ['TransID' => 'TXNOTHER1', 'TransAmount' => '100.00', 'BillRefNumber' => 'SOMETHINGELSE'])->assertOk();

        $this->assertSame(0, StandingOrderExecution::query()->count());
    }

    public function test_an_unverified_confirmation_never_records_a_debit(): void
    {
        $this->setConfig('billing.webhooks.providers.mpesa.secret', 'c2bsecret');
        $this->createOrder();
        $this->service()->handleCallback($this->activationPayload());

        $this->postJson('/billing/webhooks/mpesa', $this->debit('TXNFORGED1'));
        $this->postJson('/billing/webhooks/mpesa?secret=wrong', $this->debit('TXNFORGED2'));

        $this->assertSame(0, StandingOrderExecution::query()->count());
        $this->assertSame(0, Payment::query()->whereIn('provider_payment_id', ['TXNFORGED1', 'TXNFORGED2'])->count());
    }
}

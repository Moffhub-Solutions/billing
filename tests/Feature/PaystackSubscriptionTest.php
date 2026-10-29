<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Events\SubscriptionCreated;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Jobs\ProcessRenewals;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\PaystackSubscriptionService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class PaystackSubscriptionTest extends BaseTestCase
{
    private const SECRET = 'sk_test_subscriptions';

    private Plan $plan;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.paystack.secret_key', self::SECRET);
        config()->set('billing.providers.paystack.webhook_secret', self::SECRET);

        $this->plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'currency' => 'KES',
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $this->company = Company::create(['name' => 'Test Co']);
    }

    private function service(): PaystackSubscriptionService
    {
        return app(PaystackSubscriptionService::class);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function webhook(string $event, array $data): TestResponse
    {
        $body = (string) json_encode(['event' => $event, 'data' => $data]);

        return $this->call('POST', '/billing/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    private function syncedPlan(): void
    {
        Http::fake(['api.paystack.co/plan' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_abc']])]);
        $this->service()->syncPlan($this->plan);
        $this->plan->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function charge(string $reference, array $extra = []): array
    {
        return [
            'reference' => $reference,
            'amount' => 250000,
            'currency' => 'KES',
            'status' => 'success',
            'channel' => 'card',
            'plan' => ['plan_code' => 'PLN_abc'],
            'customer' => ['email' => 'Jane@Example.test'],
            'metadata' => [],
            ...$extra,
        ];
    }

    /**
     * @return TestResponse<Response>
     */
    private function firstCharge(string $reference = 'first-1'): TestResponse
    {
        return $this->webhook('charge.success', $this->charge($reference, ['metadata' => ['moffhub_billing' => [
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'plan_slug' => 'starter',
            'email' => 'jane@example.test',
        ]]]));
    }

    private function subscription(): Subscription
    {
        return Subscription::query()->firstOrFail();
    }

    public function test_a_plan_is_created_on_paystack_once_and_updated_when_its_price_changes(): void
    {
        Http::fake([
            'api.paystack.co/plan' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_abc']]),
            'api.paystack.co/plan/PLN_abc' => Http::response(['status' => true, 'message' => 'Plan updated']),
        ]);

        $this->assertSame('created', $this->service()->syncPlan($this->plan)['action']);
        $this->assertSame('PLN_abc', $this->service()->planCode($this->plan->refresh()));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/plan'
            && $request['interval'] === 'monthly' && $request['amount'] === 250000 && $request['currency'] === 'KES');

        $this->assertSame('unchanged', $this->service()->syncPlan($this->plan)['action']);

        $this->plan->base_price = 300000;
        $this->plan->save();
        $this->assertSame('updated', $this->service()->syncPlan($this->plan)['action']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request['amount'] === 300000 && $request['update_existing_subscriptions'] === false);
    }

    public function test_a_plan_paystack_refuses_is_reported_and_not_marked_synced(): void
    {
        Http::fake(['api.paystack.co/plan' => Http::response(['status' => false, 'message' => 'Invalid currency'], 400)]);

        $result = $this->service()->syncPlan($this->plan);

        $this->assertSame('failed', $result['action']);
        $this->assertSame('Invalid currency', $result['message']);
        $this->assertNull($this->service()->planCode($this->plan->refresh()));
    }

    public function test_starting_a_subscription_sends_the_plan_code_and_who_is_paying(): void
    {
        $this->syncedPlan();
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['reference' => 'r1', 'access_code' => 'ac', 'authorization_url' => 'https://checkout.paystack.com/x']])]);

        $result = $this->service()->start($this->company, 'starter', 'Jane@Example.test', ['callback_url' => 'https://app.test/done']);

        $this->assertSame('https://checkout.paystack.com/x', $result['metadata']['authorization_url']);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/transaction/initialize')
            && $request['plan'] === 'PLN_abc'
            && $request['channels'] === ['card']
            && $request['metadata']['moffhub_billing']['billable_id'] === $this->company->id
            && $request['metadata']['moffhub_billing']['email'] === 'jane@example.test');
    }

    public function test_the_first_payment_creates_the_subscription_and_records_the_payment(): void
    {
        $this->syncedPlan();
        Event::fake([SubscriptionCreated::class]);

        $this->firstCharge()->assertOk();

        $subscription = $this->subscription();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame('paystack', $subscription->payment_provider);
        $this->assertSame('provider', ($subscription->metadata ?? [])['billing_mode'] ?? null);
        $this->assertSame($this->company->id, $subscription->billable_id);
        $this->assertTrue($subscription->current_period_end?->isFuture() ?? false);

        $payment = Payment::query()->where('provider_payment_id', 'first-1')->firstOrFail();
        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertSame(250000, $payment->amount);
        Event::assertDispatched(SubscriptionCreated::class);
    }

    public function test_the_same_payment_delivered_twice_is_recorded_once(): void
    {
        $this->syncedPlan();

        $this->firstCharge()->assertOk();
        $this->firstCharge()->assertOk();

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_paystacks_subscription_details_attach_whichever_arrives_first(): void
    {
        $this->syncedPlan();
        $created = ['subscription_code' => 'SUB_1', 'email_token' => 'tok_1', 'status' => 'active', 'next_payment_date' => '2030-01-15T00:00:00.000Z', 'customer' => ['email' => 'jane@example.test'], 'plan' => ['plan_code' => 'PLN_abc']];

        // subscription.create first, then the first charge.
        $this->webhook('subscription.create', $created)->assertOk();
        $this->assertSame(0, Subscription::query()->count());
        $this->firstCharge()->assertOk();
        $this->assertSame('SUB_1', $this->subscription()->provider_subscription_id);

        // The token is kept encrypted.
        $this->assertNotSame('tok_1', ($this->subscription()->metadata ?? [])['paystack_email_token'] ?? null);
    }

    public function test_a_renewal_moves_the_period_records_a_payment_and_tells_the_app(): void
    {
        $this->syncedPlan();
        $this->firstCharge()->assertOk();
        $this->webhook('subscription.create', ['subscription_code' => 'SUB_1', 'email_token' => 'tok_1', 'customer' => ['email' => 'jane@example.test'], 'plan' => ['plan_code' => 'PLN_abc']])->assertOk();
        $subscription = $this->subscription();
        $subscription->current_period_end = now()->subDay();
        $subscription->status = SubscriptionStatus::PAST_DUE;
        $subscription->save();
        Event::fake([SubscriptionRenewed::class]);

        $this->webhook('charge.success', $this->charge('renew-1'))->assertOk();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status, 'a paid renewal clears past due');
        $this->assertTrue($subscription->current_period_end?->isFuture() ?? false);
        $this->assertSame(2, Payment::query()->where('status', PaymentStatus::COMPLETED)->count());
        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_the_package_does_not_also_charge_a_subscription_paystack_bills(): void
    {
        $this->syncedPlan();
        $this->firstCharge()->assertOk();
        $subscription = $this->subscription();
        $subscription->current_period_end = now()->subDay();
        $subscription->save();
        Http::fake();

        (new ProcessRenewals)->handle(app(PaymentManager::class));

        Http::assertNothingSent();
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_failed_charge_marks_it_past_due_and_tells_the_app(): void
    {
        $this->syncedPlan();
        $this->firstCharge()->assertOk();
        $this->webhook('subscription.create', ['subscription_code' => 'SUB_1', 'email_token' => 'tok_1', 'customer' => ['email' => 'jane@example.test'], 'plan' => ['plan_code' => 'PLN_abc']])->assertOk();
        Event::fake([PaymentFailed::class]);

        $this->webhook('invoice.payment_failed', ['subscription' => ['subscription_code' => 'SUB_1'], 'customer' => ['email' => 'jane@example.test'], 'amount' => 250000])->assertOk();

        $this->assertSame(SubscriptionStatus::PAST_DUE, $this->subscription()->status);
        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::FAILED)->count());
        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_cancelling_stops_paystack_billing_and_paystacks_own_cancel_is_followed(): void
    {
        $this->syncedPlan();
        $this->firstCharge()->assertOk();
        $this->webhook('subscription.create', ['subscription_code' => 'SUB_1', 'email_token' => 'tok_1', 'customer' => ['email' => 'jane@example.test'], 'plan' => ['plan_code' => 'PLN_abc']])->assertOk();
        Http::fake(['api.paystack.co/subscription/disable' => Http::response(['status' => true])]);

        $this->subscription()->cancel();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/subscription/disable')
            && $request['code'] === 'SUB_1' && $request['token'] === 'tok_1');

        Event::fake([SubscriptionCancelled::class]);
        $this->webhook('subscription.disable', ['subscription_code' => 'SUB_1', 'status' => 'cancelled', 'customer' => ['email' => 'jane@example.test'], 'plan' => ['plan_code' => 'PLN_abc']])->assertOk();

        $this->assertSame(SubscriptionStatus::CANCELLED, $this->subscription()->status);
    }

    public function test_a_one_off_payment_with_no_plan_is_not_treated_as_a_subscription(): void
    {
        $this->syncedPlan();

        $this->webhook('charge.success', ['reference' => 'oneoff-1', 'amount' => 1000, 'currency' => 'KES', 'customer' => ['email' => 'jane@example.test'], 'metadata' => []])->assertOk();

        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_a_charge_that_names_a_billable_it_may_not_use_creates_nothing(): void
    {
        $this->syncedPlan();

        $this->webhook('charge.success', $this->charge('forged-1', ['metadata' => ['moffhub_billing' => [
            'billable_type' => User::class,
            'billable_id' => 1,
            'plan_slug' => 'starter',
            'email' => 'jane@example.test',
        ]]]))->assertOk();

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_first_payment_that_was_not_a_card_is_not_left_waiting_for_paystack(): void
    {
        $this->syncedPlan();

        $this->webhook('charge.success', $this->charge('mm-1', ['channel' => 'mobile_money', 'metadata' => ['moffhub_billing' => [
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'plan_slug' => 'starter',
            'email' => 'jane@example.test',
        ]]]))->assertOk();

        $subscription = $this->subscription();
        $this->assertSame('self', ($subscription->metadata ?? [])['billing_mode'] ?? null);
        $this->assertFalse($this->service()->isProviderBilled($subscription), 'so the package, not Paystack, is responsible for renewing it');
    }

    public function test_a_paystack_billed_subscription_whose_renewal_never_arrives_goes_past_due(): void
    {
        $this->syncedPlan();
        $this->firstCharge()->assertOk();
        Event::fake([PaymentFailed::class]);

        $subscription = $this->subscription();
        $subscription->current_period_end = now()->subDay();
        $subscription->save();
        (new ProcessRenewals)->handle(app(PaymentManager::class));
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status, 'a day late is still inside the grace');

        $subscription->current_period_end = now()->subDays(5);
        $subscription->save();
        (new ProcessRenewals)->handle(app(PaymentManager::class));

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->refresh()->status);
        Event::assertDispatched(PaymentFailed::class);
    }
}

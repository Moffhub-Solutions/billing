<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Jobs\ProcessDunning;
use Moffhub\Billing\Jobs\ProcessRenewals;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\PaymentToken;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class SavedCardRenewalTest extends BaseTestCase
{
    private const SECRET = 'sk_test_saved_cards';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.paystack.secret_key', self::SECRET);
        config()->set('billing.providers.paystack.webhook_secret', self::SECRET);

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 500000,
            'currency' => 'KES',
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
        ]);
        $this->company = Company::create(['name' => 'Card Co']);
    }

    private function dueSubscription(int $daysLate = 1, SubscriptionStatus $status = SubscriptionStatus::ACTIVE): Subscription
    {
        $subscription = $this->company->subscribe('standard')->provider('paystack')->create();
        $subscription->current_period_start = now()->subDays(30 + $daysLate);
        $subscription->current_period_end = now()->subDays($daysLate);
        $subscription->status = $status;
        $subscription->save();

        return $subscription->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function saveCard(array $overrides = []): PaymentToken
    {
        return PaymentToken::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'provider' => 'paystack',
            'token_type' => 'card',
            'token' => 'AUTH_saved',
            'email' => 'jane@example.test',
            'last_four' => '4081',
            'card_brand' => 'visa',
            'is_default' => true,
            'is_reusable' => true,
            ...$overrides,
        ]);
    }

    private function fakeCharge(string $status = 'success'): void
    {
        Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response([
            'status' => true,
            'data' => ['status' => $status, 'reference' => 'bill-ref', 'id' => 99],
        ])]);
    }

    private function renew(): void
    {
        app()->call([new ProcessRenewals, 'handle']);
    }

    public function test_a_renewal_charges_the_saved_card_with_the_customers_email(): void
    {
        $subscription = $this->dueSubscription();
        $token = $this->saveCard();
        $this->fakeCharge();

        $this->renew();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/transaction/charge_authorization')
            && $request['email'] === 'jane@example.test'
            && $request['authorization_code'] === 'AUTH_saved'
            && $request['amount'] === 500000
            && $request['currency'] === 'KES'
            && str_starts_with($request['reference'], 'bill-'.$subscription->ulid.'-'));

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->current_period_end?->isFuture() ?? false);
        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::COMPLETED)->count());
        $this->assertNotNull($token->refresh()->last_used_at);
    }

    public function test_with_no_saved_card_nothing_is_sent_and_it_goes_past_due(): void
    {
        $subscription = $this->dueSubscription();
        Event::fake([PaymentFailed::class]);
        Http::fake();

        $this->renew();

        Http::assertNothingSent();
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->refresh()->status);
        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_an_expired_or_unreusable_card_is_never_charged(): void
    {
        $subscription = $this->dueSubscription();
        $this->saveCard(['expires_at' => now()->subMonth()]);
        $this->saveCard(['token' => 'AUTH_other', 'is_default' => false, 'is_reusable' => false]);
        Http::fake();

        $this->renew();

        Http::assertNothingSent();
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->refresh()->status);
    }

    public function test_a_declined_card_goes_past_due_without_extending_the_period(): void
    {
        $subscription = $this->dueSubscription();
        $end = $subscription->current_period_end;
        $this->saveCard();
        $this->fakeCharge('failed');

        $this->renew();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertEquals($end?->toIso8601String(), $subscription->current_period_end?->toIso8601String());
        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::COMPLETED)->count());
    }

    public function test_the_default_card_is_used_before_another(): void
    {
        $this->dueSubscription();
        $this->saveCard(['token' => 'AUTH_old', 'is_default' => false]);
        $this->saveCard(['token' => 'AUTH_default', 'is_default' => true]);
        $this->fakeCharge();

        $this->renew();

        Http::assertSent(fn ($request): bool => ($request['authorization_code'] ?? null) === 'AUTH_default');
    }

    public function test_a_dunning_retry_charges_the_saved_card_too(): void
    {
        $subscription = $this->dueSubscription(3, SubscriptionStatus::PAST_DUE);
        $this->saveCard();
        $this->fakeCharge();

        app()->call([new ProcessDunning, 'handle']);

        Http::assertSent(fn ($request): bool => ($request['authorization_code'] ?? null) === 'AUTH_saved'
            && str_contains((string) $request['reference'], '-dun3'));
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $authorization
     * @return TestResponse<Response>
     */
    private function paidWebhook(string $reference, array $authorization): TestResponse
    {
        $body = (string) json_encode(['event' => 'charge.success', 'data' => [
            'reference' => $reference,
            'amount' => 500000,
            'currency' => 'KES',
            'status' => 'success',
            'channel' => 'card',
            'customer' => ['email' => 'Jane@Example.test'],
            'authorization' => $authorization,
        ]]);

        return $this->call('POST', '/billing/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    private function pendingPayment(string $reference): Payment
    {
        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 500000,
            'currency' => 'KES',
            'status' => PaymentStatus::PENDING,
            'payment_provider' => 'paystack',
            'provider_payment_id' => $reference,
        ]);
        $this->company->morphMany(Payment::class, 'billable')->save($payment);

        return $payment;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $code = 'AUTH_new', bool $reusable = true): array
    {
        return [
            'authorization_code' => $code,
            'reusable' => $reusable,
            'channel' => 'card',
            'signature' => 'SIG_1',
            'last4' => '4081',
            'brand' => 'visa',
            'exp_month' => '12',
            'exp_year' => '2030',
        ];
    }

    public function test_a_reusable_card_from_a_completed_payment_is_saved_once(): void
    {
        $this->pendingPayment('pay-1');
        $this->pendingPayment('pay-2');

        $this->paidWebhook('pay-1', $this->card())->assertOk();
        $this->paidWebhook('pay-2', $this->card('AUTH_rotated'))->assertOk();

        $this->assertSame(1, PaymentToken::query()->count(), 'the same card (by signature) is not saved twice');
        $token = PaymentToken::query()->firstOrFail();
        $this->assertSame('AUTH_rotated', $token->token);
        $this->assertSame('jane@example.test', $token->email);
        $this->assertTrue($token->is_default);
        $this->assertSame('visa', $token->card_brand);
        $this->assertTrue($token->expires_at?->isSameDay(now()->setDate(2030, 12, 31)) ?? false);
        $this->assertTrue($token->isUsable());
    }

    public function test_a_card_that_cannot_be_reused_is_not_saved(): void
    {
        $this->pendingPayment('pay-1');

        $this->paidWebhook('pay-1', $this->card('AUTH_once', reusable: false))->assertOk();

        $this->assertSame(0, PaymentToken::query()->count());
    }

    public function test_a_payment_this_app_did_not_start_saves_no_card(): void
    {
        $this->paidWebhook('someone-elses', $this->card())->assertOk();

        $this->assertSame(0, PaymentToken::query()->count());
    }
}

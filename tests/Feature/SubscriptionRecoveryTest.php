<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Services\PaystackSubscriptionService;
use Moffhub\Billing\Services\SubscriptionRecovery;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class SubscriptionRecoveryTest extends BaseTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('billing.providers.mpesa', [
            'consumer_key' => 'k', 'consumer_secret' => 's', 'shortcode' => '600000', 'passkey' => 'p',
            'environment' => 'sandbox',
        ]);
        config()->set('billing.webhooks.providers.mpesa.secret', 'c2bsecret');

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 500000,
            'currency' => 'KES',
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
        ]);
        $this->company = Company::create(['name' => 'Late Co']);
    }

    private function lapsed(SubscriptionStatus $status = SubscriptionStatus::PAST_DUE, int $daysLate = 3): Subscription
    {
        $subscription = $this->company->subscribe('standard')->create();
        $subscription->current_period_start = now()->subDays(30 + $daysLate);
        $subscription->current_period_end = now()->subDays($daysLate);
        $subscription->status = $status;
        $subscription->save();

        return $subscription->refresh();
    }

    private function invoiceFor(Subscription $subscription, InvoiceStatus $status = InvoiceStatus::OVERDUE): Invoice
    {
        return Invoice::factory()->create([
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'subscription_id' => $subscription->id,
            'number' => 'INV-2026-0042',
            'status' => $status,
            'subtotal' => 500000,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total' => 500000,
            'currency' => 'KES',
        ]);
    }

    private function payByPaybill(string $amount): void
    {
        $this->postJson('/billing/webhooks/mpesa?secret=c2bsecret', [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'REC'.Str::upper(Str::random(7)),
            'TransAmount' => $amount,
            'BusinessShortCode' => '600000',
            'BillRefNumber' => 'INV-2026-0042',
            'MSISDN' => '2547****1234',
            'TransTime' => '20261029101500',
        ])->assertOk();
    }

    public function test_paying_the_overdue_invoice_by_paybill_restores_a_past_due_subscription(): void
    {
        $subscription = $this->lapsed();
        $this->invoiceFor($subscription);

        $this->payByPaybill('5000.00');

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->current_period_end?->isFuture() ?? false);
        $this->assertTrue($subscription->current_period_end->greaterThan(now()->addDays(25)), 'a paid cycle runs from payment, not from the lapsed date');
    }

    public function test_an_expired_subscription_is_restored_too(): void
    {
        $subscription = $this->lapsed(SubscriptionStatus::EXPIRED, 20);
        $this->invoiceFor($subscription);

        $this->payByPaybill('5000.00');

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    public function test_a_part_payment_does_not_restore_it(): void
    {
        $subscription = $this->lapsed();
        $this->invoiceFor($subscription);

        $this->payByPaybill('2000.00');

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->refresh()->status);
    }

    public function test_a_period_that_still_runs_is_kept_and_only_the_status_changes(): void
    {
        $subscription = $this->lapsed();
        $subscription->current_period_end = now()->addDays(5);
        $subscription->save();
        $end = $subscription->refresh()->current_period_end;
        $invoice = $this->invoiceFor($subscription, InvoiceStatus::PAID);

        $this->assertTrue(app(SubscriptionRecovery::class)->afterInvoicePaid($invoice));

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNotNull($end);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertTrue($end->equalTo($subscription->current_period_end));
    }

    public function test_an_unpaid_invoice_changes_nothing(): void
    {
        $subscription = $this->lapsed();
        $invoice = $this->invoiceFor($subscription, InvoiceStatus::OVERDUE);

        $this->assertFalse(app(SubscriptionRecovery::class)->afterInvoicePaid($invoice));
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->refresh()->status);
    }

    public function test_a_cancelled_subscription_is_never_brought_back_by_a_late_payment(): void
    {
        $subscription = $this->lapsed();
        $subscription->cancelled_at = now()->subDay();
        $subscription->status = SubscriptionStatus::EXPIRED;
        $subscription->save();
        $invoice = $this->invoiceFor($subscription, InvoiceStatus::PAID);

        $this->assertFalse(app(SubscriptionRecovery::class)->afterInvoicePaid($invoice));
        $this->assertSame(SubscriptionStatus::EXPIRED, $subscription->refresh()->status);
    }

    public function test_a_subscription_the_provider_bills_is_left_to_the_provider(): void
    {
        $subscription = $this->lapsed();
        $subscription->payment_provider = 'paystack';
        $subscription->provider_subscription_id = 'SUB_abc';
        $subscription->metadata = ['billing_mode' => PaystackSubscriptionService::MODE];
        $subscription->save();
        $invoice = $this->invoiceFor($subscription, InvoiceStatus::PAID);

        $this->assertFalse(app(SubscriptionRecovery::class)->afterInvoicePaid($invoice));
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->refresh()->status);
    }

    public function test_an_invoice_with_no_subscription_is_ignored(): void
    {
        $invoice = Invoice::factory()->create([
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'status' => InvoiceStatus::PAID,
            'total' => 1000,
            'subscription_id' => null,
        ]);

        $this->assertFalse(app(SubscriptionRecovery::class)->afterInvoicePaid($invoice));
    }
}

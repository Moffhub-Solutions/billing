<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Enums\UnmatchedPaymentStatus;
use Moffhub\Billing\Events\PaybillPaymentUnmatched;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Models\UnmatchedPayment;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class UnmatchedPaymentTest extends BaseTestCase
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
        $this->company = Company::create(['name' => 'Stray Co']);
        $this->actingAs(User::create(['name' => 'Back Office', 'email' => 'ops@example.com', 'company_id' => $this->company->id]));
        $this->grantBillingAdmin();
    }

    private function invoice(InvoiceStatus $status = InvoiceStatus::OVERDUE, int $total = 500000, ?Subscription $subscription = null, string $currency = 'KES'): Invoice
    {
        return Invoice::factory()->create([
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'subscription_id' => $subscription?->id,
            'number' => 'INV-2026-'.random_int(1000, 9999),
            'status' => $status,
            'subtotal' => $total,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total' => $total,
            'currency' => $currency,
        ]);
    }

    private function stray(string $transId = 'STRAY001', string $amount = '5000.00', string $account = 'MY-RENT'): void
    {
        $this->postJson('/billing/webhooks/mpesa?secret=c2bsecret', [
            'TransactionType' => 'Pay Bill',
            'TransID' => $transId,
            'TransAmount' => $amount,
            'BusinessShortCode' => '600000',
            'BillRefNumber' => $account,
            'MSISDN' => '2547****1234',
            'TransTime' => '20261029101500',
        ])->assertOk();
    }

    private function open(string $transId = 'STRAY001'): UnmatchedPayment
    {
        $this->stray($transId);

        return UnmatchedPayment::query()->where('provider_payment_id', $transId)->firstOrFail();
    }

    public function test_a_payment_naming_no_invoice_is_kept_as_a_record_once(): void
    {
        Event::fake([PaybillPaymentUnmatched::class]);

        $this->stray();
        $this->stray(); // Safaricom redelivers until answered.

        $this->assertSame(1, UnmatchedPayment::query()->count());
        $record = UnmatchedPayment::query()->firstOrFail();
        $this->assertSame(UnmatchedPaymentStatus::OPEN, $record->status);
        $this->assertSame(500000, $record->amount);
        $this->assertSame('MY-RENT', $record->account_reference);
        $this->assertSame('2026-10-29', $record->paid_at?->format('Y-m-d'));
        $this->assertSame(0, Payment::query()->count(), 'nothing is credited until a person decides');
    }

    public function test_allocating_credits_the_invoice_and_restores_its_lapsed_subscription(): void
    {
        Event::fake([PaymentReceived::class]);
        $subscription = $this->company->subscribe('standard')->create();
        $subscription->status = SubscriptionStatus::PAST_DUE;
        $subscription->current_period_end = now()->subDays(4);
        $subscription->save();
        $invoice = $this->invoice(subscription: $subscription);
        $record = $this->open();

        $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/allocate", ['invoice_id' => $invoice->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'allocated');

        $payment = Payment::query()->where('provider_payment_id', 'STRAY001')->firstOrFail();
        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame(InvoiceStatus::PAID, $invoice->refresh()->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
        $this->assertSame($payment->id, $record->refresh()->payment_id);
        $this->assertNotNull($record->resolved_at);
        Event::assertDispatched(PaymentReceived::class);

        // The redelivery after allocation is a known payment, not a new stray.
        $this->stray();
        $this->assertSame(1, UnmatchedPayment::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_an_amount_above_what_was_owed_is_kept_and_flagged(): void
    {
        $invoice = $this->invoice(total: 300000);
        $record = $this->open();

        $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/allocate", ['invoice_id' => $invoice->id])->assertOk();

        $payment = Payment::query()->where('provider_payment_id', 'STRAY001')->firstOrFail();
        $this->assertSame(500000, $payment->amount);
        $this->assertSame(200000, $payment->metadata['overpaid_by'] ?? null);
    }

    public function test_an_invoice_that_is_not_open_or_in_another_currency_is_refused(): void
    {
        $record = $this->open();

        foreach ([
            $this->invoice(InvoiceStatus::PAID),
            $this->invoice(InvoiceStatus::VOID),
            $this->invoice(InvoiceStatus::DRAFT),
            $this->invoice(currency: 'USD'),
        ] as $invoice) {
            $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/allocate", ['invoice_id' => $invoice->id])->assertStatus(422);
        }

        $this->assertSame(UnmatchedPaymentStatus::OPEN, $record->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_payment_can_only_be_resolved_once(): void
    {
        $invoice = $this->invoice();
        $record = $this->open();

        $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/allocate", ['invoice_id' => $invoice->id])->assertOk();
        $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/allocate", ['invoice_id' => $this->invoice()->id])->assertStatus(422);
        $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/dismiss", ['reason' => 'late'])->assertStatus(422);

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_dismissing_needs_a_reason_and_credits_nothing(): void
    {
        $record = $this->open();

        $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/dismiss", [])->assertStatus(422);
        $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/dismiss", ['reason' => 'Meant for another biller, refund outside billing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed')
            ->assertJsonPath('data.resolution_notes', 'Meant for another biller, refund outside billing');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_list_filters_by_status(): void
    {
        $this->stray('STRAY001');
        $this->stray('STRAY002');
        $dismissed = UnmatchedPayment::query()->where('provider_payment_id', 'STRAY002')->firstOrFail();
        $this->postJson("/api/billing/admin/unmatched-payments/{$dismissed->id}/dismiss", ['reason' => 'x'])->assertOk();

        $this->getJson('/api/billing/admin/unmatched-payments?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider_payment_id', 'STRAY001');
        $this->getJson('/api/billing/admin/unmatched-payments')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_it_is_back_office_only(): void
    {
        $this->stray();
        $record = UnmatchedPayment::query()->firstOrFail();
        Gate::define('__billing_admin_denied', fn (): bool => false);
        config()->set('billing.admin_gate', '__billing_admin_denied');

        $this->getJson('/api/billing/admin/unmatched-payments')->assertForbidden();
        $this->postJson("/api/billing/admin/unmatched-payments/{$record->id}/dismiss", ['reason' => 'x'])->assertForbidden();
    }
}

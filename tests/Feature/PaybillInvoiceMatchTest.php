<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Events\PaybillPaymentUnmatched;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class PaybillInvoiceMatchTest extends BaseTestCase
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

        $this->company = Company::create(['name' => 'Paybill Co']);
    }

    private function invoice(string $number = 'INV-2026-0007', InvoiceStatus $status = InvoiceStatus::SENT, int $total = 500000): Invoice
    {
        return Invoice::factory()->create([
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'number' => $number,
            'status' => $status,
            'subtotal' => $total,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total' => $total,
            'currency' => 'KES',
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function pay(string $transId, string $amount, string $account, string $secret = 'c2bsecret'): TestResponse
    {
        return $this->postJson('/billing/webhooks/mpesa'.($secret === '' ? '' : '?secret='.$secret), [
            'TransactionType' => 'Pay Bill',
            'TransID' => $transId,
            'TransAmount' => $amount,
            'BusinessShortCode' => '600000',
            'BillRefNumber' => $account,
            'MSISDN' => '2547****1234',
            'TransTime' => '20261029101500',
        ]);
    }

    public function test_a_payment_naming_an_invoice_is_recorded_and_pays_it(): void
    {
        $invoice = $this->invoice();
        Event::fake([PaymentReceived::class]);

        $this->pay('TXN100', '5000.00', 'INV-2026-0007')->assertOk();

        $payment = Payment::query()->where('provider_payment_id', 'TXN100')->firstOrFail();
        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame(500000, $payment->amount);
        $this->assertSame($this->company->id, $payment->billable_id);
        $this->assertSame(InvoiceStatus::PAID, $invoice->refresh()->status);
        Event::assertDispatched(PaymentReceived::class);
    }

    public function test_the_account_is_matched_however_the_customer_typed_it(): void
    {
        $invoice = $this->invoice();

        $this->pay('TXN101', '5000.00', ' inv 2026 0007 ')->assertOk();

        $this->assertSame(InvoiceStatus::PAID, $invoice->refresh()->status);
    }

    public function test_a_part_payment_leaves_the_invoice_partly_paid(): void
    {
        $invoice = $this->invoice();

        $this->pay('TXN102', '2000.00', 'INV-2026-0007')->assertOk();

        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $invoice->refresh()->status);
        $this->pay('TXN103', '3000.00', 'INV-2026-0007')->assertOk();
        $this->assertSame(InvoiceStatus::PAID, $invoice->refresh()->status);
    }

    public function test_an_overpayment_is_kept_and_flagged(): void
    {
        $invoice = $this->invoice();

        $this->pay('TXN104', '6000.00', 'INV-2026-0007')->assertOk();

        $payment = Payment::query()->where('provider_payment_id', 'TXN104')->firstOrFail();
        $this->assertSame(600000, $payment->amount);
        $this->assertSame(100000, ($payment->metadata ?? [])['overpaid_by'] ?? null);
        $this->assertSame(InvoiceStatus::PAID, $invoice->refresh()->status);
    }

    public function test_a_payment_that_names_no_invoice_tells_the_app_and_records_nothing(): void
    {
        $this->invoice();
        Event::fake([PaybillPaymentUnmatched::class]);

        $this->pay('TXN105', '5000.00', 'NOSUCH-1')->assertOk();

        $this->assertSame(0, Payment::query()->count());
        Event::assertDispatched(PaybillPaymentUnmatched::class, fn (PaybillPaymentUnmatched $event): bool => $event->transactionId === 'TXN105'
            && $event->amount === 500000 && $event->accountReference === 'NOSUCH-1');
    }

    public function test_void_and_draft_invoices_are_never_matched(): void
    {
        $this->invoice('INV-2026-0008', InvoiceStatus::VOID);
        $this->invoice('INV-2026-0009', InvoiceStatus::DRAFT);
        Event::fake([PaybillPaymentUnmatched::class]);

        $this->pay('TXN106', '5000.00', 'INV-2026-0008')->assertOk();
        $this->pay('TXN107', '5000.00', 'INV-2026-0009')->assertOk();

        $this->assertSame(0, Payment::query()->count());
        Event::assertDispatchedTimes(PaybillPaymentUnmatched::class, 2);
    }

    public function test_a_redelivered_confirmation_is_recorded_once(): void
    {
        $invoice = $this->invoice();

        $this->pay('TXN108', '5000.00', 'INV-2026-0007')->assertOk();
        $this->pay('TXN108', '5000.00', 'INV-2026-0007')->assertOk();

        $this->assertSame(1, Payment::query()->where('provider_payment_id', 'TXN108')->count());
        $this->assertSame(InvoiceStatus::PAID, $invoice->refresh()->status);
    }

    public function test_an_unverified_confirmation_records_nothing(): void
    {
        $this->invoice();

        $this->pay('TXN109', '5000.00', 'INV-2026-0007', secret: '');
        $this->pay('TXN110', '5000.00', 'INV-2026-0007', secret: 'wrong');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_matching_can_be_turned_off(): void
    {
        $this->invoice();
        config()->set('billing.providers.mpesa.match_invoices', false);

        $this->pay('TXN111', '5000.00', 'INV-2026-0007')->assertOk();

        $this->assertSame(0, Payment::query()->count());
    }
}

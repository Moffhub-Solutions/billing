<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\InvoiceItem;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class InvoiceModelTest extends BaseTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Test Co']);
    }

    public function test_create_invoice(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-001',
            'status' => 'draft',
            'subtotal' => 100000,
            'tax_amount' => 16000,
            'total' => 116000,
            'currency' => 'KES',
            'tax_rate' => 16.0,
        ]);

        $this->assertNotNull($invoice->id);
        $this->assertEquals('INV-001', $invoice->number);
        $this->assertEquals(116000, $invoice->total);
    }

    public function test_invoice_has_items(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-002',
            'status' => 'draft',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Starter Plan - Monthly',
            'quantity' => 1,
            'unit_price' => 100000,
            'total' => 100000,
        ]);

        $this->assertCount(1, $invoice->items);
        $this->assertEquals('Starter Plan - Monthly', $invoice->items->first()->description);
    }

    public function test_invoice_has_payments(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-003',
            'status' => 'sent',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
        ]);

        Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'amount' => 50000,
            'currency' => 'KES',
            'status' => 'completed',
        ]);

        $this->assertCount(1, $invoice->payments);
    }

    public function test_is_paid(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-004',
            'status' => 'paid',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
        ]);

        $this->assertTrue($invoice->isPaid());

        $invoice->update(['status' => 'draft']);
        $invoice->refresh();

        $this->assertFalse($invoice->isPaid());
    }

    public function test_is_overdue(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-005',
            'status' => 'sent',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
            'due_date' => now()->subDay(),
        ]);

        $this->assertTrue($invoice->isOverdue());

        // Paid invoices are not overdue even with past due date
        $invoice->update(['status' => 'paid']);
        $invoice->refresh();

        $this->assertFalse($invoice->isOverdue());
    }

    public function test_outstanding_balance(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-006',
            'status' => 'sent',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
        ]);

        // No payments yet
        $this->assertEquals(100000, $invoice->outstandingBalance());

        // Partial payment
        Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'amount' => 40000,
            'currency' => 'KES',
            'status' => 'completed',
        ]);

        $this->assertEquals(60000, $invoice->outstandingBalance());
    }

    public function test_formatted_total(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-007',
            'status' => 'draft',
            'subtotal' => 250000,
            'total' => 250000,
            'currency' => 'KES',
        ]);

        $this->assertEquals('KES 2,500.00', $invoice->formattedTotal());
    }

    public function test_invoice_status_casting(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-008',
            'status' => 'paid',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
        ]);

        $this->assertInstanceOf(InvoiceStatus::class, $invoice->status);
        $this->assertEquals(InvoiceStatus::PAID, $invoice->status);
    }

    public function test_date_casting(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-009',
            'status' => 'draft',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
            'due_date' => '2025-12-31',
            'paid_at' => now(),
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $invoice->due_date);
        $this->assertInstanceOf(\Carbon\Carbon::class, $invoice->paid_at);
    }

    public function test_morphable(): void
    {
        $invoice = Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-010',
            'status' => 'draft',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
        ]);

        $billable = $invoice->billable;

        $this->assertInstanceOf(Company::class, $billable);
        $this->assertEquals($this->company->id, $billable->id);
    }
}

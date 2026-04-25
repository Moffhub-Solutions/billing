<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class InvoiceApiTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Invoice Test Co']);
        $this->user = User::create([
            'name' => 'Invoice User',
            'email' => 'invoice@example.com',
            'company_id' => $this->company->id,
        ]);
    }

    // ─── List Invoices ──────────────────────────────────────────────────

    public function test_list_invoices_success(): void
    {
        $this->createInvoice(['status' => InvoiceStatus::DRAFT]);
        $this->createInvoice(['status' => InvoiceStatus::PAID]);

        $response = $this->actingAs($this->user)->getJson('/api/billing/invoices');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'number', 'status', 'total']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_list_invoices_filtered_by_status(): void
    {
        $this->createInvoice(['status' => InvoiceStatus::DRAFT]);
        $this->createInvoice(['status' => InvoiceStatus::PAID]);
        $this->createInvoice(['status' => InvoiceStatus::PAID]);

        $response = $this->actingAs($this->user)->getJson('/api/billing/invoices?status=paid');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'paid');
    }

    public function test_list_invoices_pagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createInvoice(['status' => InvoiceStatus::DRAFT]);
        }

        $response = $this->actingAs($this->user)->getJson('/api/billing/invoices?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_list_invoices_no_billable(): void
    {
        $orphanUser = User::create([
            'name' => 'Orphan',
            'email' => 'orphan@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($orphanUser)->getJson('/api/billing/invoices');

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    // ─── Create Invoice ─────────────────────────────────────────────────

    public function test_create_invoice_with_line_items(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/invoices', [
            'items' => [
                [
                    'description' => 'Monthly subscription',
                    'quantity' => 1,
                    'unit_price' => 250000,
                ],
                [
                    'description' => 'Add-on: OCR Scanning',
                    'quantity' => 2,
                    'unit_price' => 100000,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Invoice created.')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency', 'KES');

        // Verify subtotal = 250000 + 200000 = 450000
        $this->assertEquals(450000, $response->json('data.subtotal'));

        // Tax at 16%: 72000
        $this->assertEquals(72000, $response->json('data.tax_amount'));

        // Total = 450000 + 72000 = 522000
        $this->assertEquals(522000, $response->json('data.total'));

        // Verify items
        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertCount(2, $items);

        // Verify database
        $this->assertDatabaseCount(billing_table('invoices', 'billing_invoices'), 1);
        $this->assertDatabaseCount(billing_table('invoice_items', 'billing_invoice_items'), 2);
    }

    public function test_create_invoice_tax_calculation_at_16_percent(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/invoices', [
            'tax_rate' => 16,
            'items' => [
                [
                    'description' => 'Service fee',
                    'quantity' => 1,
                    'unit_price' => 100000,
                ],
            ],
        ]);

        $response->assertCreated();

        $this->assertEquals(100000, $response->json('data.subtotal'));
        $this->assertEquals(16000, $response->json('data.tax_amount'));
        $this->assertEquals(116000, $response->json('data.total'));
        $this->assertEquals(16.0, $response->json('data.tax_rate'));
    }

    public function test_create_invoice_validation_errors(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/invoices', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_create_invoice_empty_items(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/invoices', [
            'items' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_create_invoice_no_billable(): void
    {
        $orphanUser = User::create([
            'name' => 'Orphan',
            'email' => 'orphan2@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($orphanUser)->postJson('/api/billing/invoices', [
            'items' => [
                ['description' => 'Test', 'unit_price' => 1000],
            ],
        ]);

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    // ─── Show Invoice ───────────────────────────────────────────────────

    public function test_show_invoice_with_items(): void
    {
        $invoice = $this->createInvoice(['status' => InvoiceStatus::SENT]);
        $invoice->items()->create([
            'description' => 'Line item 1',
            'quantity' => 1,
            'unit_price' => 50000,
            'total' => 50000,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/billing/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonCount(1, 'data.items');
    }

    public function test_show_invoice_not_found(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/billing/invoices/99999');

        $response->assertNotFound();
    }

    // ─── Send Invoice ───────────────────────────────────────────────────

    public function test_send_invoice_success_from_draft(): void
    {
        $invoice = $this->createInvoice(['status' => InvoiceStatus::DRAFT]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/send");

        $response->assertOk()
            ->assertJsonPath('message', 'Invoice marked as sent.')
            ->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas(billing_table('invoices', 'billing_invoices'), [
            'id' => $invoice->id,
            'status' => 'sent',
        ]);
    }

    public function test_send_invoice_error_if_not_draft(): void
    {
        $invoice = $this->createInvoice(['status' => InvoiceStatus::SENT]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/send");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Only draft invoices can be sent.');
    }

    // ─── Void Invoice ───────────────────────────────────────────────────

    public function test_void_invoice_success(): void
    {
        $invoice = $this->createInvoice(['status' => InvoiceStatus::SENT]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/void");

        $response->assertOk()
            ->assertJsonPath('message', 'Invoice voided.')
            ->assertJsonPath('data.status', 'void');
    }

    public function test_void_invoice_error_if_paid(): void
    {
        $invoice = $this->createInvoice(['status' => InvoiceStatus::PAID]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/void");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot void a paid invoice. Issue a credit note instead.');
    }

    // ─── Mark Paid ──────────────────────────────────────────────────────

    public function test_mark_paid_success(): void
    {
        Event::fake([PaymentReceived::class]);

        $invoice = $this->createInvoice([
            'status' => InvoiceStatus::SENT,
            'total' => 100000,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/mark-paid");

        $response->assertOk()
            ->assertJsonPath('message', 'Invoice marked as paid.')
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas(billing_table('invoices', 'billing_invoices'), [
            'id' => $invoice->id,
            'status' => 'paid',
        ]);
    }

    public function test_mark_paid_already_paid_error(): void
    {
        $invoice = $this->createInvoice(['status' => InvoiceStatus::PAID]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/mark-paid");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Invoice is already paid.');
    }

    public function test_mark_paid_with_payment_reference(): void
    {
        Event::fake([PaymentReceived::class]);

        $invoice = $this->createInvoice([
            'status' => InvoiceStatus::SENT,
            'total' => 50000,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/mark-paid", [
            'payment_reference' => 'CHK-12345',
            'notes' => 'Paid by cheque',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid');

        // Verify the metadata includes the manual payment info
        $freshInvoice = Invoice::find($invoice->id);
        $this->assertNotNull($freshInvoice);
        $metadata = $freshInvoice->metadata ?? [];
        $manualPayment = $metadata['manual_payment'] ?? null;
        $this->assertIsArray($manualPayment);
        $this->assertEquals('CHK-12345', $manualPayment['reference'] ?? null);
        $this->assertEquals('Paid by cheque', $manualPayment['notes'] ?? null);
    }

    public function test_mark_paid_creates_payment_record(): void
    {
        Event::fake([PaymentReceived::class]);

        $invoice = $this->createInvoice([
            'status' => InvoiceStatus::SENT,
            'total' => 75000,
            'currency' => 'KES',
        ]);

        $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/mark-paid", [
            'payment_reference' => 'REF-001',
        ]);

        $this->assertDatabaseHas(billing_table('payments', 'billing_payments'), [
            'invoice_id' => $invoice->id,
            'amount' => 75000,
            'currency' => 'KES',
            'status' => 'completed',
            'payment_provider' => 'manual',
            'provider_reference' => 'REF-001',
        ]);
    }

    public function test_mark_paid_dispatches_payment_received_event(): void
    {
        Event::fake([PaymentReceived::class]);

        $invoice = $this->createInvoice([
            'status' => InvoiceStatus::SENT,
            'total' => 100000,
        ]);

        $this->actingAs($this->user)->postJson("/api/billing/invoices/{$invoice->id}/mark-paid");

        Event::assertDispatched(PaymentReceived::class);
    }

    // ─── Sequential Invoice Numbering ───────────────────────────────────

    public function test_sequential_invoice_numbering(): void
    {
        $response1 = $this->actingAs($this->user)->postJson('/api/billing/invoices', [
            'items' => [
                ['description' => 'First invoice', 'unit_price' => 10000],
            ],
        ]);

        $response1->assertCreated();
        $this->assertEquals('INV-2026-0001', $response1->json('data.number'));

        $response2 = $this->actingAs($this->user)->postJson('/api/billing/invoices', [
            'items' => [
                ['description' => 'Second invoice', 'unit_price' => 20000],
            ],
        ]);

        $response2->assertCreated();
        $this->assertEquals('INV-2026-0002', $response2->json('data.number'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createInvoice(array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'ulid' => Str::ulid()->toBase32(),
            'number' => 'INV-'.Str::random(8),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'status' => InvoiceStatus::DRAFT,
            'currency' => 'KES',
            'subtotal' => 0,
            'tax_rate' => 16.0,
            'tax_amount' => 0,
            'total' => 0,
        ], $attributes));
    }
}

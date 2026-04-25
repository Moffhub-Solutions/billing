<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Models\SubscriptionAddon;
use Moffhub\Billing\Services\InvoiceService;
use Moffhub\Billing\Services\KenyanTaxCalculator;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use PHPUnit\Framework\Attributes\Test;

class InvoiceServiceTest extends BaseTestCase
{
    private InvoiceService $service;

    private Company $company;

    private Plan $plan;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InvoiceService(new KenyanTaxCalculator);

        $this->company = Company::create(['name' => 'Test Company']);

        $this->plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Professional',
            'slug' => 'professional',
            'base_price' => 10000,
            'currency' => 'KES',
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => [],
        ]);

        $this->subscription = Subscription::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);
    }

    #[Test]
    public function it_generates_invoice_for_subscription(): void
    {
        $invoice = $this->service->generateForSubscription($this->subscription);

        $this->assertSame(InvoiceStatus::DRAFT, $invoice->status);
        $this->assertSame('KES', $invoice->currency);
        $this->assertSame(10000, $invoice->subtotal);
        $this->assertSame(1600, $invoice->tax_amount); // 16% VAT
        $this->assertSame(11600, $invoice->total);
        $this->assertSame(16.0, $invoice->tax_rate);
        $this->assertNotEmpty($invoice->number);
        $this->assertNotNull($invoice->due_date);
    }

    #[Test]
    public function it_generates_invoice_with_correct_line_items(): void
    {
        $invoice = $this->service->generateForSubscription($this->subscription);

        $this->assertCount(1, $invoice->items);

        $item = $invoice->items->first();
        $this->assertNotNull($item);
        $this->assertSame('Professional - Monthly', $item->description);
        $this->assertSame(1, $item->quantity);
        $this->assertSame(10000, $item->unit_price);
        $this->assertSame(10000, $item->total);
    }

    #[Test]
    public function it_generates_invoice_including_addons(): void
    {
        $feature = Feature::create([
            'slug' => 'extra-storage',
            'name' => 'Extra Storage',
            'type' => 'boolean',
            'is_addon' => true,
            'addon_price' => 2000,
            'is_active' => true,
        ]);

        SubscriptionAddon::create([
            'subscription_id' => $this->subscription->id,
            'feature_id' => $feature->id,
            'status' => 'active',
            'price_override' => null,
            'enabled_at' => now(),
        ]);

        $invoice = $this->service->generateForSubscription($this->subscription);

        $this->assertCount(2, $invoice->items);
        $this->assertSame(12000, $invoice->subtotal); // 10000 + 2000
        $this->assertSame(1920, $invoice->tax_amount); // 16% of 12000
        $this->assertSame(13920, $invoice->total);

        $addonItem = $invoice->items->where('feature_slug', 'extra-storage')->first();
        $this->assertNotNull($addonItem);
        $this->assertSame('Add-on: Extra Storage', $addonItem->description);
        $this->assertSame(2000, $addonItem->unit_price);
    }

    #[Test]
    public function it_generates_sequential_invoice_numbers(): void
    {
        $invoice1 = $this->service->generateForSubscription($this->subscription);
        $invoice2 = $this->service->generateForSubscription($this->subscription);

        $year = now()->year;
        $prefixRaw = config('billing.invoices.prefix', 'INV');
        $prefix = is_string($prefixRaw) ? $prefixRaw : 'INV';

        $this->assertSame("{$prefix}-{$year}-0001", $invoice1->number);
        $this->assertSame("{$prefix}-{$year}-0002", $invoice2->number);
    }

    #[Test]
    public function it_creates_credit_note_with_negative_total(): void
    {
        $invoice = $this->service->generateForSubscription($this->subscription);

        $creditNote = $this->service->generateCreditNote($invoice, 5000, 'Partial refund');

        $this->assertSame(-5000, $creditNote->total);
        $this->assertSame(-5000, $creditNote->subtotal);
        $this->assertSame(InvoiceStatus::PAID, $creditNote->status);
        $this->assertTrue($creditNote->isCreditNote());
        $this->assertNotNull($creditNote->paid_at);
    }

    #[Test]
    public function it_credit_note_references_original_invoice(): void
    {
        $invoice = $this->service->generateForSubscription($this->subscription);

        $creditNote = $this->service->generateCreditNote($invoice, 3000, 'Service issue');

        $metadata = $creditNote->metadata ?? [];
        $this->assertSame($invoice->id, $metadata['original_invoice_id'] ?? null);
        $this->assertTrue($metadata['is_credit_note'] ?? false);
        $this->assertSame('Service issue', $metadata['reason'] ?? null);

        $original = $creditNote->creditNoteFor();
        $this->assertNotNull($original);
        $this->assertSame($invoice->id, $original->id);
    }

    #[Test]
    public function it_marks_overdue_invoices(): void
    {
        // Create a past-due invoice
        $pastDueInvoice = new Invoice([
            'ulid' => Str::ulid()->toBase32(),
            'number' => 'INV-TEST-0001',
            'subscription_id' => $this->subscription->id,
            'status' => InvoiceStatus::SENT,
            'currency' => 'KES',
            'subtotal' => 10000,
            'tax_amount' => 1600,
            'total' => 11600,
            'tax_rate' => 16.0,
            'due_date' => now()->subDays(5),
        ]);
        $this->company->morphMany(Invoice::class, 'billable')->save($pastDueInvoice);

        // Create a non-overdue invoice
        $futureInvoice = new Invoice([
            'ulid' => Str::ulid()->toBase32(),
            'number' => 'INV-TEST-0002',
            'subscription_id' => $this->subscription->id,
            'status' => InvoiceStatus::SENT,
            'currency' => 'KES',
            'subtotal' => 10000,
            'tax_amount' => 1600,
            'total' => 11600,
            'tax_rate' => 16.0,
            'due_date' => now()->addDays(10),
        ]);
        $this->company->morphMany(Invoice::class, 'billable')->save($futureInvoice);

        $count = $this->service->markOverdue();

        $this->assertSame(1, $count);
        $pastDueFresh = $pastDueInvoice->fresh();
        $futureFresh = $futureInvoice->fresh();
        $this->assertNotNull($pastDueFresh);
        $this->assertNotNull($futureFresh);
        $this->assertSame(InvoiceStatus::OVERDUE, $pastDueFresh->status);
        $this->assertSame(InvoiceStatus::SENT, $futureFresh->status);
    }

    #[Test]
    public function it_does_not_mark_paid_or_void_invoices_as_overdue(): void
    {
        // Create a paid invoice with past due date
        $paidInvoice = new Invoice([
            'ulid' => Str::ulid()->toBase32(),
            'number' => 'INV-TEST-0003',
            'subscription_id' => $this->subscription->id,
            'status' => InvoiceStatus::PAID,
            'currency' => 'KES',
            'subtotal' => 10000,
            'tax_amount' => 1600,
            'total' => 11600,
            'tax_rate' => 16.0,
            'due_date' => now()->subDays(5),
            'paid_at' => now()->subDays(10),
        ]);
        $this->company->morphMany(Invoice::class, 'billable')->save($paidInvoice);

        $count = $this->service->markOverdue();

        $this->assertSame(0, $count);
        $paidFresh = $paidInvoice->fresh();
        $this->assertNotNull($paidFresh);
        $this->assertSame(InvoiceStatus::PAID, $paidFresh->status);
    }
}

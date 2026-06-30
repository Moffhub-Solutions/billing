<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\PaymentProofStatus;
use Moffhub\Billing\Events\PaymentProofSubmitted;
use Moffhub\Billing\Events\PaymentProofVerified;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\PaymentProof;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class PaymentProofApiTest extends BaseTestCase
{
    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Proof Co']);
        $this->user = User::create([
            'name' => 'Back Office',
            'email' => 'ops@example.com',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
        $this->grantBillingAdmin();
    }

    private function invoice(int $total = 100000): Invoice
    {
        return Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'number' => 'INV-PROOF-'.Str::random(5),
            'status' => InvoiceStatus::SENT,
            'subtotal' => $total,
            'total' => $total,
            'currency' => 'KES',
        ]);
    }

    public function test_submitting_a_proof_records_it_pending_without_settling(): void
    {
        Event::fake([PaymentProofSubmitted::class, PaymentReceived::class]);
        $invoice = $this->invoice();

        $response = $this->postJson('/api/billing/admin/payment-proofs', [
            'invoice_id' => $invoice->id,
            'channel' => 'family_bank', // a channel the library does not integrate
            'reference' => 'FB-REF-123',
            'amount' => 100000,
            'payer_name' => 'Jane Doe',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('billing_payment_proofs', [
            'invoice_id' => $invoice->id,
            'channel' => 'family_bank',
            'status' => 'pending',
        ]);
        // Submitting must not settle the invoice or create a payment.
        $this->assertNotSame(InvoiceStatus::PAID, $invoice->fresh()?->status);
        $this->assertSame(0, $invoice->payments()->count());

        Event::assertDispatched(PaymentProofSubmitted::class);
        Event::assertNotDispatched(PaymentReceived::class);
    }

    public function test_verifying_a_proof_creates_payment_and_settles_invoice(): void
    {
        Event::fake([PaymentProofVerified::class, PaymentReceived::class]);
        $invoice = $this->invoice(100000);

        $this->postJson('/api/billing/admin/payment-proofs', [
            'invoice_id' => $invoice->id,
            'channel' => 'mpesa',
            'reference' => 'QXY12345',
            'amount' => 100000,
        ])->assertCreated();

        $proof = PaymentProof::query()->firstOrFail();

        $this->postJson("/api/billing/admin/payment-proofs/{$proof->id}/verify", ['notes' => 'Confirmed on statement'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $proof->refresh();
        $this->assertSame(PaymentProofStatus::VERIFIED, $proof->status);
        $this->assertNotNull($proof->payment_id);

        // A completed offline payment now settles the invoice.
        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()?->status);
        $this->assertDatabaseHas('billing_payments', [
            'id' => $proof->payment_id,
            'payment_provider' => 'offline',
            'payment_method' => 'offline',
            'status' => 'completed',
        ]);

        Event::assertDispatched(PaymentReceived::class);
        Event::assertDispatched(PaymentProofVerified::class);
    }

    public function test_rejecting_a_proof_creates_no_payment(): void
    {
        $invoice = $this->invoice();
        $this->postJson('/api/billing/admin/payment-proofs', [
            'invoice_id' => $invoice->id,
            'channel' => 'bank_transfer',
            'amount' => 100000,
        ])->assertCreated();

        $proof = PaymentProof::query()->firstOrFail();

        $this->postJson("/api/billing/admin/payment-proofs/{$proof->id}/reject", ['reason' => 'Could not find the deposit'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertNull($proof->refresh()->payment_id);
        $this->assertSame(0, $invoice->fresh()?->payments()->count());
    }

    public function test_a_verified_proof_cannot_be_verified_again(): void
    {
        $invoice = $this->invoice();
        $this->postJson('/api/billing/admin/payment-proofs', [
            'invoice_id' => $invoice->id,
            'channel' => 'mpesa',
            'amount' => 100000,
        ])->assertCreated();

        $proof = PaymentProof::query()->firstOrFail();
        $this->postJson("/api/billing/admin/payment-proofs/{$proof->id}/verify")->assertOk();

        // A second verify must be rejected (no double settlement).
        $this->postJson("/api/billing/admin/payment-proofs/{$proof->id}/verify")->assertStatus(422);

        $this->assertSame(1, $invoice->fresh()?->payments()->count());
    }

    public function test_proof_routes_are_admin_gated(): void
    {
        $this->config()->set('billing.admin_gate', null);
        $this->config()->set('billing.admin_bypass_method', null);

        $invoice = $this->invoice();

        $this->postJson('/api/billing/admin/payment-proofs', [
            'invoice_id' => $invoice->id,
            'channel' => 'mpesa',
            'amount' => 100000,
        ])->assertStatus(403);

        $this->assertSame(0, PaymentProof::query()->count());
    }
}

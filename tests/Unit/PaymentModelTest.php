<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class PaymentModelTest extends BaseTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Test Co']);
    }

    public function test_create_payment(): void
    {
        $payment = Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'amount' => 500000,
            'currency' => 'KES',
            'status' => 'pending',
            'payment_provider' => 'manual',
        ]);

        $this->assertGreaterThan(0, $payment->id);
        $this->assertEquals(500000, $payment->amount);
        $this->assertEquals('KES', $payment->currency);
    }

    public function test_is_completed(): void
    {
        $payment = Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'amount' => 500000,
            'currency' => 'KES',
            'status' => 'completed',
        ]);

        $this->assertTrue($payment->isCompleted());

        $payment->update(['status' => 'pending']);
        $payment->refresh();

        $this->assertFalse($payment->isCompleted());
    }

    public function test_is_pending(): void
    {
        $payment = Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'amount' => 500000,
            'currency' => 'KES',
            'status' => 'pending',
        ]);

        $this->assertTrue($payment->isPending());

        $payment->update(['status' => 'completed']);
        $payment->refresh();

        $this->assertFalse($payment->isPending());
    }

    public function test_formatted_amount(): void
    {
        $payment = Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'amount' => 750000,
            'currency' => 'KES',
            'status' => 'completed',
        ]);

        $this->assertEquals('KES 7,500.00', $payment->formattedAmount());
    }

    public function test_payment_status_casting(): void
    {
        $payment = Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'amount' => 500000,
            'currency' => 'KES',
            'status' => 'completed',
        ]);

        $this->assertInstanceOf(PaymentStatus::class, $payment->status);
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
    }

    public function test_morphable(): void
    {
        $payment = Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
            'amount' => 500000,
            'currency' => 'KES',
            'status' => 'pending',
        ]);

        $billable = $payment->billable;

        $this->assertInstanceOf(Company::class, $billable);
        $this->assertEquals($this->company->id, $billable->id);
    }
}

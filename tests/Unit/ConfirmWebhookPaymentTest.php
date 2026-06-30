<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Jobs\ConfirmWebhookPayment;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\WebhookSettlement;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class ConfirmWebhookPaymentTest extends BaseTestCase
{
    private function fakeManagerReturning(string $status): PaymentManager
    {
        $driver = $this->createMock(PaymentProviderInterface::class);
        $driver->method('getPaymentStatus')->willReturn($status);

        $manager = $this->createMock(PaymentManager::class);
        $manager->method('driver')->willReturn($driver);

        return $manager;
    }

    private function pendingPayment(string $providerPaymentId): Payment
    {
        $company = Company::create(['name' => 'Re-query Co']);
        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 10000,
            'currency' => 'KES',
            'status' => PaymentStatus::PENDING,
            'payment_provider' => 'mpesa',
            'provider_payment_id' => $providerPaymentId,
        ]);
        $company->payments()->save($payment);

        return $payment;
    }

    public function test_settles_when_provider_confirms_completed(): void
    {
        Event::fake([PaymentReceived::class]);
        $payment = $this->pendingPayment('stk_1');

        $job = new ConfirmWebhookPayment('mpesa', 'stk_1');
        $job->handle($this->fakeManagerReturning('completed'), $this->app()->make(WebhookSettlement::class));

        $this->assertSame(PaymentStatus::COMPLETED, $payment->refresh()->status);
        Event::assertDispatched(PaymentReceived::class);
    }

    public function test_does_not_settle_when_provider_still_pending(): void
    {
        $payment = $this->pendingPayment('stk_2');

        $job = new ConfirmWebhookPayment('mpesa', 'stk_2');
        // A forged/early callback: the provider's API does not confirm it.
        $job->handle($this->fakeManagerReturning('pending'), $this->app()->make(WebhookSettlement::class));

        $this->assertSame(PaymentStatus::PENDING, $payment->refresh()->status);
    }

    public function test_no_op_when_no_pending_payment_exists(): void
    {
        // A forged callback naming an unknown id must not query or settle anything.
        $manager = $this->createMock(PaymentManager::class);
        $manager->expects($this->never())->method('driver');

        $job = new ConfirmWebhookPayment('mpesa', 'does_not_exist');
        $job->handle($manager, $this->app()->make(WebhookSettlement::class));

        $this->assertSame(0, Payment::query()->where('provider_payment_id', 'does_not_exist')->count());
    }
}

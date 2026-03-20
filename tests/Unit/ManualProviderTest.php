<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Moffhub\Billing\Providers\ManualProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class ManualProviderTest extends BaseTestCase
{
    protected ManualProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new ManualProvider;
    }

    public function test_charge_returns_pending(): void
    {
        $result = $this->provider->charge(100000, 'KES');

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertStringStartsWith('manual_', $result['provider_payment_id']);
        $this->assertNull($result['provider_reference']);
        $this->assertEquals('cash', $result['metadata']['payment_type']);
    }

    public function test_charge_with_options(): void
    {
        $result = $this->provider->charge(100000, 'KES', [
            'reference' => 'REF-001',
            'payment_type' => 'bank_transfer',
            'notes' => 'Wire transfer from client',
            'recorded_by' => 'admin@example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('REF-001', $result['provider_reference']);
        $this->assertEquals('bank_transfer', $result['metadata']['payment_type']);
        $this->assertEquals('Wire transfer from client', $result['metadata']['notes']);
        $this->assertEquals('admin@example.com', $result['metadata']['recorded_by']);
    }

    public function test_refund_returns_completed(): void
    {
        $result = $this->provider->refund('manual_abc123');

        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $result['status']);
        $this->assertStringStartsWith('manual_refund_', $result['provider_refund_id']);
    }

    public function test_get_payment_status(): void
    {
        $status = $this->provider->getPaymentStatus('manual_abc123');

        $this->assertEquals('pending', $status);
    }

    public function test_verify_webhook_always_true(): void
    {
        $request = Request::create('/webhook', 'POST');

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook(): void
    {
        $request = Request::create('/webhook', 'POST');

        $result = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.manual', $result['event']);
        $this->assertNull($result['provider_payment_id']);
        $this->assertEquals('pending', $result['status']);
        $this->assertNull($result['amount']);
        $this->assertNull($result['currency']);
        $this->assertIsArray($result['metadata']);
    }

    public function test_is_configured(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_get_name(): void
    {
        $this->assertEquals('manual', $this->provider->getName());
    }
}

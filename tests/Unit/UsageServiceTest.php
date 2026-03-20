<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\UsageEvent;
use Moffhub\Billing\Services\UsageService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class UsageServiceTest extends BaseTestCase
{
    protected UsageService $usageService;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usageService = app(UsageService::class);

        Plan::create([
            'ulid' => '01JTEST000000000000000020',
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'ocr_scanning'],
            'limits' => ['ocr_scanning' => 100],
        ]);

        $this->company = Company::create(['name' => 'Test Co']);
        $this->company->subscribe('standard')->create();
    }

    public function test_record_usage(): void
    {
        $event = $this->usageService->record($this->company, 'ocr_scanning');

        $this->assertInstanceOf(UsageEvent::class, $event);
        $this->assertEquals(1, $event->quantity);
        $this->assertEquals(1, $this->usageService->getUsage($this->company, 'ocr_scanning'));
    }

    public function test_record_usage_with_quantity(): void
    {
        $this->usageService->record($this->company, 'ocr_scanning', quantity: 5);

        $this->assertEquals(5, $this->usageService->getUsage($this->company, 'ocr_scanning'));
    }

    public function test_deduplication_by_transaction_id(): void
    {
        $this->usageService->record($this->company, 'ocr_scanning', transactionId: 'txn-001');
        $this->usageService->record($this->company, 'ocr_scanning', transactionId: 'txn-001');
        $this->usageService->record($this->company, 'ocr_scanning', transactionId: 'txn-002');

        // Only txn-001 and txn-002 should count (txn-001 deduplicated)
        $this->assertEquals(2, UsageEvent::count());
    }

    public function test_is_within_limit(): void
    {
        // Record 99 of 100 limit
        $this->usageService->record($this->company, 'ocr_scanning', quantity: 99);
        $this->assertTrue($this->usageService->isWithinLimit($this->company, 'ocr_scanning'));

        // Record 1 more to hit limit
        $this->usageService->record($this->company, 'ocr_scanning', quantity: 1);
        $this->assertFalse($this->usageService->isWithinLimit($this->company, 'ocr_scanning'));
    }

    public function test_remaining_quota_via_billable(): void
    {
        $this->usageService->record($this->company, 'ocr_scanning', quantity: 42);

        $this->assertEquals(58, $this->company->remainingQuota('ocr_scanning'));
    }
}

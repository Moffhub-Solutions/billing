<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Models\UsageRecord;
use Moffhub\Billing\Services\UsageService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class BillableResolutionTest extends BaseTestCase
{
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'ulid' => '01JTEST000000000000000080',
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'incidents'],
            'limits' => ['max_posts' => 10],
        ]);
    }

    // ── Company as billable (not User) ───────────────────────────

    public function test_company_can_be_billable(): void
    {
        $company = Company::create(['name' => 'Acme Corp']);

        $subscription = $company->subscribe('standard')->create();

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertEquals($company->getMorphClass(), $subscription->billable_type);
        $this->assertEquals($company->id, $subscription->billable_id);
    }

    public function test_company_billable_has_all_trait_methods(): void
    {
        $company = Company::create(['name' => 'Acme Corp']);
        $company->subscribe('standard')->create();

        $this->assertTrue($company->subscribed());
        $this->assertTrue($company->onPlan('standard'));
        $this->assertFalse($company->onPlan('professional'));
        $this->assertTrue($company->hasFeature('gatebook'));
        $this->assertFalse($company->hasFeature('hr_module'));
        $this->assertEquals(10, $company->usageLimit('max_posts'));
        $this->assertEquals(0, $company->usage('max_posts'));
    }

    public function test_multiple_companies_are_isolated(): void
    {
        $companyA = Company::create(['name' => 'Company A']);
        $companyB = Company::create(['name' => 'Company B']);

        $companyA->subscribe('standard')->create();
        // Company B has no subscription

        $this->assertTrue($companyA->subscribed());
        $this->assertFalse($companyB->subscribed());
        $this->assertTrue($companyA->hasFeature('gatebook'));
        $this->assertFalse($companyB->hasFeature('gatebook'));
    }

    // ── Multi-tenant usage scoping ───────────────────────────────

    public function test_usage_is_scoped_to_billable(): void
    {
        $companyA = Company::create(['name' => 'Company A']);
        $companyB = Company::create(['name' => 'Company B']);

        $companyA->subscribe('standard')->create();
        $companyB->subscribe('standard')->create();

        $usageService = app(UsageService::class);

        // Record usage for company A
        $usageService->record($companyA, 'max_posts', 3);
        $usageService->record($companyA, 'max_posts', 2);

        // Record usage for company B
        $usageService->record($companyB, 'max_posts', 1);

        // Each company sees only its own usage
        $this->assertEquals(5, $companyA->usage('max_posts'));
        $this->assertEquals(1, $companyB->usage('max_posts'));
    }

    public function test_usage_records_store_correct_billable_morph(): void
    {
        $company = Company::create(['name' => 'Test Co']);
        $company->subscribe('standard')->create();

        $usageService = app(UsageService::class);
        $usageService->record($company, 'max_posts', 1);

        $record = UsageRecord::where('billable_id', $company->id)
            ->where('billable_type', $company->getMorphClass())
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals($company->getMorphClass(), $record->billable_type);
        $this->assertEquals($company->id, $record->billable_id);
        $this->assertEquals('max_posts', $record->feature_slug);
    }

    public function test_usage_limits_are_per_billable(): void
    {
        $companyA = Company::create(['name' => 'Company A']);
        $companyB = Company::create(['name' => 'Company B']);

        $companyA->subscribe('standard')->create();
        $companyB->subscribe('standard')->create();

        $usageService = app(UsageService::class);

        // Company A uses all their quota
        $usageService->record($companyA, 'max_posts', 10);

        // Company B still has full quota
        $this->assertEquals(0, $companyA->remainingQuota('max_posts'));
        $this->assertEquals(10, $companyB->remainingQuota('max_posts'));
    }

    // ── Payments scoped to billable ──────────────────────────────

    public function test_payments_are_scoped_to_billable(): void
    {
        $companyA = Company::create(['name' => 'Company A']);
        $companyB = Company::create(['name' => 'Company B']);

        Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $companyA->getMorphClass(),
            'billable_id' => $companyA->id,
            'amount' => 500000,
            'currency' => 'KES',
            'status' => 'completed',
        ]);

        Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $companyB->getMorphClass(),
            'billable_id' => $companyB->id,
            'amount' => 300000,
            'currency' => 'KES',
            'status' => 'completed',
        ]);

        $this->assertCount(1, $companyA->payments);
        $this->assertCount(1, $companyB->payments);
        $aFirst = $companyA->payments->first();
        $bFirst = $companyB->payments->first();
        $this->assertNotNull($aFirst);
        $this->assertNotNull($bFirst);
        $this->assertEquals(500000, $aFirst->amount);
        $this->assertEquals(300000, $bFirst->amount);
    }

    // ── Invoices scoped to billable ──────────────────────────────

    public function test_invoices_queried_by_billable(): void
    {
        $companyA = Company::create(['name' => 'Company A']);
        $companyB = Company::create(['name' => 'Company B']);

        Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $companyA->getMorphClass(),
            'billable_id' => $companyA->id,
            'number' => 'INV-001',
            'status' => 'draft',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'KES',
        ]);

        Invoice::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $companyB->getMorphClass(),
            'billable_id' => $companyB->id,
            'number' => 'INV-002',
            'status' => 'draft',
            'subtotal' => 200000,
            'total' => 200000,
            'currency' => 'KES',
        ]);

        // Query by billable_type + billable_id (same pattern as controllers)
        $companyAInvoices = Invoice::where('billable_type', $companyA->getMorphClass())
            ->where('billable_id', $companyA->id)
            ->get();

        $this->assertCount(1, $companyAInvoices);
        $first = $companyAInvoices->first();
        $this->assertNotNull($first);
        $this->assertEquals('INV-001', $first->number);
    }

    // ── billable_relation config ─────────────────────────────────

    public function test_billable_relation_config_exists(): void
    {
        $relation = config('billing.billable_relation');

        $this->assertNotNull($relation);
        $this->assertEquals('company', $relation);
    }

    public function test_billable_model_config_defaults_to_company(): void
    {
        // In test env it's set to the fixture Company; in default config it's App\Models\Company
        $model = config('billing.billable_model');

        $this->assertIsString($model);
        $this->assertStringContainsString('Company', $model);
    }
}

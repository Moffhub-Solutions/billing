<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Exceptions\FeatureNotAvailableException;
use Moffhub\Billing\Exceptions\UsageLimitExceededException;
use Moffhub\Billing\Http\Middleware\CheckFeatureAccess;
use Moffhub\Billing\Http\Middleware\CheckPlanAccess;
use Moffhub\Billing\Http\Middleware\CheckUsageLimit;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Services\UsageService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class MiddlewareTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Feature::create(['slug' => 'gatebook', 'name' => 'Gatebook', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'shifts', 'name' => 'Shifts', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'ocr_scanning', 'name' => 'OCR', 'type' => FeatureType::METERED, 'is_active' => true]);

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook'],
            'limits' => ['ocr_scanning' => 5],
        ]);

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Professional',
            'slug' => 'professional',
            'base_price' => 1500000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'shifts', 'ocr_scanning'],
            'limits' => ['ocr_scanning' => 500],
        ]);

        $this->company = Company::create(['name' => 'Test Co']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'company_id' => $this->company->id,
        ]);
    }

    // ─── CheckFeatureAccess Middleware ──────────────────────────────────

    public function test_feature_middleware_passes_when_feature_available(): void
    {
        $this->company->subscribe('starter')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $middleware = new CheckFeatureAccess;
        $response = $middleware->handle($request, fn () => new Response('OK'), 'gatebook');

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_feature_middleware_blocks_when_feature_unavailable(): void
    {
        $this->company->subscribe('starter')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $this->expectException(FeatureNotAvailableException::class);

        $middleware = new CheckFeatureAccess;
        $middleware->handle($request, fn () => new Response('OK'), 'shifts');
    }

    public function test_feature_middleware_blocks_without_subscription(): void
    {
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $this->expectException(FeatureNotAvailableException::class);

        $middleware = new CheckFeatureAccess;
        $middleware->handle($request, fn () => new Response('OK'), 'gatebook');
    }

    public function test_feature_middleware_blocks_without_auth(): void
    {
        $request = Request::create('/test');
        $request->setUserResolver(fn () => null);

        $this->expectException(FeatureNotAvailableException::class);

        $middleware = new CheckFeatureAccess;
        $middleware->handle($request, fn () => new Response('OK'), 'gatebook');
    }

    public function test_feature_middleware_checks_multiple_features(): void
    {
        $this->company->subscribe('professional')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $middleware = new CheckFeatureAccess;

        // All features available on pro plan
        $response = $middleware->handle($request, fn () => new Response('OK'), 'gatebook,shifts');
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_feature_middleware_fails_if_any_feature_missing(): void
    {
        $this->company->subscribe('starter')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $this->expectException(FeatureNotAvailableException::class);

        $middleware = new CheckFeatureAccess;
        // gatebook is available, shifts is NOT on starter
        $middleware->handle($request, fn () => new Response('OK'), 'gatebook,shifts');
    }

    // ─── CheckPlanAccess Middleware ─────────────────────────────────────

    public function test_plan_middleware_passes_when_on_plan(): void
    {
        $this->company->subscribe('starter')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $middleware = new CheckPlanAccess;
        $response = $middleware->handle($request, fn () => new Response('OK'), 'starter');

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_plan_middleware_passes_with_any_matching_plan(): void
    {
        $this->company->subscribe('professional')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $middleware = new CheckPlanAccess;
        // OR logic — professional matches
        $response = $middleware->handle($request, fn () => new Response('OK'), 'professional,enterprise');

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_plan_middleware_blocks_when_on_wrong_plan(): void
    {
        $this->company->subscribe('starter')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $this->expectException(FeatureNotAvailableException::class);

        $middleware = new CheckPlanAccess;
        $middleware->handle($request, fn () => new Response('OK'), 'professional,enterprise');
    }

    // ─── CheckUsageLimit Middleware ─────────────────────────────────────

    public function test_usage_middleware_passes_when_within_limit(): void
    {
        $this->company->subscribe('starter')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $middleware = new CheckUsageLimit;
        $response = $middleware->handle($request, fn () => new Response('OK'), 'ocr_scanning');

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_usage_middleware_blocks_when_limit_reached(): void
    {
        $this->company->subscribe('starter')->create();

        // Use up all 5 allowed scans
        $usageService = app(UsageService::class);
        $usageService->record($this->company, 'ocr_scanning', 5);

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $this->expectException(UsageLimitExceededException::class);

        $middleware = new CheckUsageLimit;
        $middleware->handle($request, fn () => new Response('OK'), 'ocr_scanning');
    }

    public function test_usage_middleware_passes_when_unlimited(): void
    {
        $this->company->subscribe('starter')->create();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        // max_posts has a limit of 2, but ocr_scanning on starter is not in features
        // Let's test with a feature that has no limit set
        $middleware = new CheckUsageLimit;
        $response = $middleware->handle($request, fn () => new Response('OK'), 'max_guards');

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_usage_middleware_allows_overage_when_configured(): void
    {
        config(['billing.usage.allow_overage' => true]);

        $this->company->subscribe('starter')->create();

        // Exceed the limit
        $usageService = app(UsageService::class);
        $usageService->record($this->company, 'ocr_scanning', 10);

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->user);

        config(['billing.billable_relation' => 'company']);

        $middleware = new CheckUsageLimit;
        $response = $middleware->handle($request, fn () => new Response('OK'), 'ocr_scanning');

        // Should pass because overage is allowed
        $this->assertEquals('OK', $response->getContent());
    }
}

<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Exceptions\CouponException;
use Moffhub\Billing\Exceptions\FeatureNotAvailableException;
use Moffhub\Billing\Exceptions\PaymentFailedException;
use Moffhub\Billing\Exceptions\UsageLimitExceededException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExceptionTest extends TestCase
{
    // FeatureNotAvailableException tests

    public function test_feature_not_available_no_subscription(): void
    {
        $exception = FeatureNotAvailableException::noSubscription();

        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertEquals(403, $exception->getStatusCode());
        $this->assertStringContainsString('No active subscription', $exception->getMessage());
    }

    public function test_feature_not_included(): void
    {
        $exception = FeatureNotAvailableException::featureNotIncluded('analytics');

        $this->assertEquals(403, $exception->getStatusCode());
        $this->assertStringContainsString('analytics', $exception->getMessage());
        $this->assertStringContainsString('not included', $exception->getMessage());
    }

    public function test_plan_required(): void
    {
        $exception = FeatureNotAvailableException::planRequired(['professional', 'enterprise']);

        $this->assertEquals(403, $exception->getStatusCode());
        $this->assertStringContainsString('professional', $exception->getMessage());
        $this->assertStringContainsString('enterprise', $exception->getMessage());
    }

    // UsageLimitExceededException tests

    public function test_usage_limit_no_subscription(): void
    {
        $exception = UsageLimitExceededException::noSubscription();

        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertEquals(429, $exception->getStatusCode());
        $this->assertStringContainsString('No active subscription', $exception->getMessage());
    }

    public function test_usage_limit_reached_with_details(): void
    {
        $exception = UsageLimitExceededException::limitReached('ocr_scanning', 100, 100);

        $this->assertEquals(429, $exception->getStatusCode());
        $this->assertEquals('ocr_scanning', $exception->featureSlug);
        $this->assertEquals(100, $exception->currentUsage);
        $this->assertEquals(100, $exception->limit);
        $this->assertStringContainsString('ocr_scanning', $exception->getMessage());
        $this->assertStringContainsString('100', $exception->getMessage());
    }

    // CouponException tests

    public function test_coupon_invalid_code(): void
    {
        $exception = CouponException::invalidCode('BADCODE');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertStringContainsString('BADCODE', $exception->getMessage());
        $this->assertStringContainsString('not valid', $exception->getMessage());
    }

    public function test_coupon_expired(): void
    {
        $exception = CouponException::expired('OLDCODE');

        $this->assertStringContainsString('OLDCODE', $exception->getMessage());
        $this->assertStringContainsString('expired', $exception->getMessage());
    }

    public function test_coupon_restrictions_failed(): void
    {
        $errors = ['Minimum amount required.', 'First time only.'];
        $exception = CouponException::restrictionsFailed($errors);

        $this->assertStringContainsString('Minimum amount required.', $exception->getMessage());
        $this->assertStringContainsString('First time only.', $exception->getMessage());
    }

    public function test_coupon_not_applicable(): void
    {
        $exception = CouponException::notApplicableToPlan('Enterprise');

        $this->assertStringContainsString('Enterprise', $exception->getMessage());
        $this->assertStringContainsString('does not apply', $exception->getMessage());
    }

    // PaymentFailedException tests

    public function test_payment_failed_from_provider(): void
    {
        $exception = PaymentFailedException::fromProvider('mpesa', 'Insufficient funds');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('mpesa', $exception->provider);
        $this->assertEquals('Insufficient funds', $exception->providerMessage);
        $this->assertStringContainsString('mpesa', $exception->getMessage());
        $this->assertStringContainsString('Insufficient funds', $exception->getMessage());
    }
}

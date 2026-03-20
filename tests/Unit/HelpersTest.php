<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Services\BillingService;
use Moffhub\Billing\Tests\BaseTestCase;

class HelpersTest extends BaseTestCase
{
    public function test_billing_helper_returns_service(): void
    {
        $result = billing();

        $this->assertInstanceOf(BillingService::class, $result);
    }

    public function test_billing_helper_is_singleton(): void
    {
        $first = billing();
        $second = billing();

        $this->assertSame($first, $second);
    }
}

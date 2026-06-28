<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Exceptions\TransactionLimitException;
use Moffhub\Billing\Services\PaymentSplitter;
use Moffhub\Billing\Tests\BaseTestCase;

class PaymentSplitterTest extends BaseTestCase
{
    protected PaymentSplitter $splitter;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->splitter = new PaymentSplitter;
    }

    public function test_amount_within_cap_is_a_single_tranche(): void
    {
        $tranches = $this->splitter->split(25_000_000, ['max_amount' => 25_000_000]);

        $this->assertSame([25_000_000], $tranches);
    }

    public function test_amount_over_cap_splits_fill_max(): void
    {
        $tranches = $this->splitter->split(50_000_000, ['max_amount' => 25_000_000]);

        $this->assertSame([25_000_000, 25_000_000], $tranches);
    }

    public function test_split_leaves_remainder_as_final_tranche(): void
    {
        $tranches = $this->splitter->split(51_000_000, ['max_amount' => 25_000_000]);

        $this->assertSame([25_000_000, 25_000_000, 1_000_000], $tranches);
        $this->assertSame(51_000_000, array_sum($tranches));
    }

    public function test_no_cap_returns_single_tranche(): void
    {
        $tranches = $this->splitter->split(99_000_000, ['max_amount' => null]);

        $this->assertSame([99_000_000], $tranches);
    }

    public function test_requires_split(): void
    {
        $limits = ['max_amount' => 25_000_000];

        $this->assertFalse($this->splitter->requiresSplit(25_000_000, $limits));
        $this->assertTrue($this->splitter->requiresSplit(25_000_001, $limits));
        $this->assertFalse($this->splitter->requiresSplit(50_000_000, ['max_amount' => null]));
    }

    public function test_within_daily_cap_succeeds(): void
    {
        // 500k needs 2 tranches, max_per_day 2 -> OK
        $tranches = $this->splitter->split(50_000_000, ['max_amount' => 25_000_000, 'max_per_day' => 2], 'mpesa');

        $this->assertCount(2, $tranches);
    }

    public function test_exceeding_daily_cap_throws(): void
    {
        // 750k needs 3 tranches, max_per_day 2 -> error
        $this->expectException(TransactionLimitException::class);

        $this->splitter->split(75_000_000, ['max_amount' => 25_000_000, 'max_per_day' => 2], 'mpesa');
    }

    public function test_daily_cap_accounts_for_used_today(): void
    {
        // 500k needs 2 tranches, but 1 already used today with cap 2 -> only 1 remains -> error
        $this->expectException(TransactionLimitException::class);

        $this->splitter->split(50_000_000, ['max_amount' => 25_000_000, 'max_per_day' => 2], 'mpesa', usedToday: 1);
    }

    public function test_exception_carries_context(): void
    {
        try {
            $this->splitter->split(75_000_000, ['max_amount' => 25_000_000, 'max_per_day' => 2], 'mpesa');
            $this->fail('Expected TransactionLimitException');
        } catch (TransactionLimitException $e) {
            $this->assertSame('mpesa', $e->provider);
            $this->assertSame(3, $e->tranchesNeeded);
            $this->assertSame(2, $e->tranchesAllowed);
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}

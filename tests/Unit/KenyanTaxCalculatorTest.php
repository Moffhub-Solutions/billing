<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Services\KenyanTaxCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class KenyanTaxCalculatorTest extends TestCase
{
    private KenyanTaxCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new KenyanTaxCalculator;
    }

    #[Test]
    public function it_calculates_standard_vat_at_16_percent(): void
    {
        $result = $this->calculator->calculate(10000, 'KES');

        $this->assertSame(1600, $result['tax_amount']);
        $this->assertSame(16.0, $result['tax_rate']);
        $this->assertSame('VAT', $result['tax_label']);
        $this->assertSame(1600, $result['breakdown']['vat']);
    }

    #[Test]
    public function it_calculates_standard_vat_with_explicit_category(): void
    {
        $result = $this->calculator->calculate(5000, 'KES', ['tax_category' => 'standard']);

        $this->assertSame(800, $result['tax_amount']);
        $this->assertSame(16.0, $result['tax_rate']);
        $this->assertSame(800, $result['breakdown']['vat']);
    }

    #[Test]
    public function it_returns_zero_tax_for_zero_rated_category(): void
    {
        $result = $this->calculator->calculate(10000, 'KES', ['tax_category' => 'zero_rated']);

        $this->assertSame(0, $result['tax_amount']);
        $this->assertSame(0.0, $result['tax_rate']);
        $this->assertSame(0, $result['breakdown']['vat']);
    }

    #[Test]
    public function it_returns_zero_tax_for_exempt_category(): void
    {
        $result = $this->calculator->calculate(10000, 'KES', ['tax_category' => 'exempt']);

        $this->assertSame(0, $result['tax_amount']);
        $this->assertSame(0.0, $result['tax_rate']);
        $this->assertSame(0, $result['breakdown']['vat']);
    }

    #[Test]
    public function it_calculates_wht_when_requested(): void
    {
        $result = $this->calculator->calculate(10000, 'KES', ['apply_wht' => true]);

        // VAT: 1600 + WHT: 500 = 2100
        $this->assertSame(2100, $result['tax_amount']);
        $this->assertSame(1600, $result['breakdown']['vat']);
        $this->assertSame(500, $result['breakdown']['wht']);
    }

    #[Test]
    public function it_calculates_combined_vat_and_wht(): void
    {
        $result = $this->calculator->calculate(20000, 'KES', [
            'tax_category' => 'standard',
            'apply_wht' => true,
        ]);

        $this->assertSame(3200, $result['breakdown']['vat']); // 16% of 20000
        $this->assertSame(1000, $result['breakdown']['wht']); // 5% of 20000
        $this->assertSame(4200, $result['tax_amount']);
    }

    #[Test]
    public function it_identifies_exempt_categories(): void
    {
        $this->assertTrue($this->calculator->isExempt(['tax_category' => 'exempt']));
        $this->assertTrue($this->calculator->isExempt(['tax_category' => 'zero_rated']));
        $this->assertFalse($this->calculator->isExempt(['tax_category' => 'standard']));
        $this->assertFalse($this->calculator->isExempt([]));
    }

    #[Test]
    public function it_applies_zero_vat_but_still_wht_for_exempt_with_wht(): void
    {
        $result = $this->calculator->calculate(10000, 'KES', [
            'tax_category' => 'exempt',
            'apply_wht' => true,
        ]);

        $this->assertSame(0, $result['breakdown']['vat']);
        $this->assertSame(500, $result['breakdown']['wht']);
        $this->assertSame(500, $result['tax_amount']);
    }
}

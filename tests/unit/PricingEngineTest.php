<?php
use ArvanReseller\Pricing\PricingEngine;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    public function test_global_markup_matches_hackathon_example(): void
    {
        // Arvan base 10,000,000 + 20% markup = 12,000,000 customer price.
        $quote = PricingEngine::quote(10000000, 20.0);
        $this->assertSame(12000000, $quote['customer_price']);
        $this->assertSame(2000000, $quote['margin']);
        $this->assertSame('global', $quote['markup_source']);
        $this->assertSame('IRT', $quote['currency']);
    }

    public function test_product_markup_overrides_global(): void
    {
        $quote = PricingEngine::quote(1000000, 20.0, 35.0);
        $this->assertSame(1350000, $quote['customer_price']);
        $this->assertSame('product', $quote['markup_source']);
    }

    public function test_customer_markup_overrides_product_and_global(): void
    {
        $quote = PricingEngine::quote(1000000, 20.0, 35.0, 0, ['markup_percent' => 10.0]);
        $this->assertSame(1100000, $quote['customer_price']);
        $this->assertSame('customer', $quote['markup_source']);
    }

    public function test_customer_discount_stacks_after_markup(): void
    {
        // 1,000,000 * 1.2 = 1,200,000 → 10% discount → 1,080,000
        $quote = PricingEngine::quote(1000000, 20.0, null, 0, ['discount_percent' => 10.0]);
        $this->assertSame(1080000, $quote['customer_price']);
        $this->assertSame(10.0, $quote['discount_percent']);
    }

    public function test_fixed_adjustment_applies_and_customer_override_wins(): void
    {
        $this->assertSame(1250000, PricingEngine::quote(1000000, 20.0, null, 50000)['customer_price']);
        $quote = PricingEngine::quote(1000000, 20.0, null, 50000, ['fixed_adjustment' => -100000]);
        $this->assertSame(1100000, $quote['customer_price']);
    }

    public function test_price_never_negative_and_markup_floored(): void
    {
        $this->assertSame(0, PricingEngine::quote(1000, 0.0, null, -5000)['customer_price']);
        $this->assertSame(0, PricingEngine::quote(1000000, -250.0)['customer_price']); // floored at -100%
    }

    public function test_discount_capped_at_100_percent(): void
    {
        $quote = PricingEngine::quote(1000000, 0.0, null, 0, ['discount_percent' => 500.0]);
        $this->assertSame(0, $quote['customer_price']);
        $this->assertSame(100.0, $quote['discount_percent']);
    }

    public function test_negative_base_cost_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PricingEngine::quote(-1, 20.0);
    }

    public function test_snapshot_carries_all_audit_fields(): void
    {
        $quote = PricingEngine::quote(500000, 25.0);
        foreach (['base_cost', 'markup_percent', 'markup_source', 'customer_price', 'margin', 'currency', 'pricing_version', 'quoted_at'] as $field) {
            $this->assertArrayHasKey($field, $quote);
        }
        $this->assertSame($quote['customer_price'] - $quote['base_cost'], $quote['margin']);
    }
}

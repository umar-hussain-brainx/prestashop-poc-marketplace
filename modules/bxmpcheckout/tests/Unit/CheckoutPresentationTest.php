<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Tests;

use BrainX\Marketplace\Domain\Optimizer\BasketLine;
use BrainX\Marketplace\Domain\Optimizer\CartOptimizer;
use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Optimizer\OfferCandidate;
use BrainX\Marketplace\Domain\Optimizer\ShippingPolicy;
use BrainX\Marketplace\Domain\Shipping\ShippingRule;
use BrainX\MarketplaceCheckout\Checkout\AllocationPresenter;
use BrainX\MarketplaceCheckout\Checkout\CartPricePlan;
use PHPUnit\Framework\TestCase;

final class CheckoutPresentationTest extends TestCase
{
    /** @return ShippingPolicy[] demo vendors A, B, C */
    private function policies(): array
    {
        return [
            ShippingPolicy::flat(1, 5.0, 30.0, null, null, 'Vendor A'),
            ShippingPolicy::flat(2, 5.0, 20.0, null, null, 'Vendor B'),
            new ShippingPolicy(3, [new ShippingRule(3, 6.90, 60.0, 25.0, 2.90), new ShippingRule(3, 0, null, null, null, 10, '09', true, 10)], 'Vendor C'),
        ];
    }

    private function clientScenario(): array
    {
        $offers = [
            new OfferCandidate(1, 1, 20, 0, 5.0, 50), new OfferCandidate(2, 1, 21, 0, 40.0, 50),
            new OfferCandidate(3, 2, 20, 0, 20.0, 50), new OfferCandidate(4, 2, 21, 0, 25.0, 50),
            new OfferCandidate(5, 3, 20, 0, 22.0, 50), new OfferCandidate(6, 3, 21, 0, 45.0, 50),
        ];
        $dest = new Destination(10, '20121');
        $result = (new CartOptimizer())->optimize([new BasketLine(20, 0, 1), new BasketLine(21, 0, 1)], $offers, $this->policies(), $dest);

        return [$result, $dest];
    }

    public function testClientScenarioPricePlanHasNoBlending(): void
    {
        [$result] = $this->clientScenario();
        $plan = CartPricePlan::fromResult($result);
        self::assertSame(['20-0' => 5.0, '21-0' => 25.0], $plan->unitPrices);
        self::assertTrue($plan->isExact(CartPricePlan::ROUND_ITEM));
        self::assertSame([20, 0], CartPricePlan::splitKey('20-0'));
    }

    public function testBlendedUnitPriceAndRoundingDrift(): void
    {
        // Hummingbird t-shirt combination 2, qty 5: 3 from B @ 22.23 + 2 from A @ 26.77 = 120.23
        $offers = [new OfferCandidate(9, 1, 1, 2, 26.77, 14), new OfferCandidate(10, 2, 1, 2, 22.23, 3)];
        $result = (new CartOptimizer())->optimize([new BasketLine(1, 2, 5)], $offers, $this->policies(), new Destination(10, '20121'));
        self::assertEqualsWithDelta(120.23, $result->itemsTotal, 1e-9);
        $plan = CartPricePlan::fromResult($result);
        self::assertSame(24.046, $plan->unitPrices['1-2']);

        $line = $plan->roundingReport(CartPricePlan::ROUND_LINE)['1-2'];
        self::assertSame(120.23, $line['ps_line_total']);
        self::assertEqualsWithDelta(0.0, $line['delta'], 1e-9);
        self::assertTrue($plan->isExact(CartPricePlan::ROUND_LINE));

        $item = $plan->roundingReport(CartPricePlan::ROUND_ITEM)['1-2'];
        self::assertSame(120.25, $item['ps_line_total']); // 24.05 × 5
        self::assertEqualsWithDelta(0.02, $item['delta'], 1e-9);
        self::assertFalse($plan->isExact(CartPricePlan::ROUND_ITEM));
    }

    public function testAllocationViewModel(): void
    {
        [$result, $dest] = $this->clientScenario();
        $view = (new AllocationPresenter())->present($result, $this->policies(), $dest, ['20-0' => 'Product 1', '21-0' => 'Product 2']);

        self::assertSame(35.0, $view['total']);
        self::assertSame(2, $view['vendor_count']);
        [$a, $b] = $view['vendors'];
        self::assertSame('Vendor A', $a['name']);
        self::assertSame('Product 1', $a['lines'][0]['name']);
        self::assertSame('€5.00', $a['shipping_fmt']);
        self::assertSame('free', $a['hint']['type']);
        self::assertSame(25.0, $a['hint']['missing']);
        self::assertSame(16, $a['hint']['progress']);
        self::assertSame('Add €25.00 more from Vendor A for free shipping', $a['hint']['text']);
        self::assertSame('Free', $b['shipping_fmt']);
        self::assertNull($b['hint']);
        self::assertSame('€5.00 · free from €20.00', $b['rule_summary']);

        $selected = array_values(array_filter($view['alternatives'], static fn ($x) => $x['selected']));
        self::assertCount(1, $selected);
        self::assertSame(35.0, $selected[0]['total']);
        // Best single vendor is €45 (A: 5 + 40, free shipping from €30) → saves €10
        self::assertSame([['label' => 'vs. best single-vendor order (Vendor A)', 'amount' => 10.0, 'amount_fmt' => '€10.00']], $view['savings']);
        self::assertSame([], $view['split_lines']);
    }

    public function testReducedThresholdHintAndRuleSummary(): void
    {
        $rule = new ShippingRule(3, 6.90, 60.0, 25.0, 2.90);
        $p = new AllocationPresenter();
        $h = $p->hint($rule, 22.0, 6.90, 'Vendor C');
        self::assertSame('reduced', $h['type']);
        self::assertSame(3.0, $h['missing']);
        self::assertSame(4.0, $h['saves']);
        self::assertSame('Add €3.00 more from Vendor C for reduced shipping (€2.90)', $h['text']);
        $h2 = $p->hint($rule, 30.0, 2.90, 'Vendor C');
        self::assertSame('free', $h2['type']);
        self::assertSame(30.0, $h2['missing']);
        self::assertNull($p->hint($rule, 70.0, 0.0, 'Vendor C'));
        self::assertSame('€6.90 · €2.90 from €25.00 · free from €60.00', AllocationPresenter::ruleSummary($rule));
        self::assertSame('Free shipping', AllocationPresenter::ruleSummary(new ShippingRule(1, 0.0)));
    }
}

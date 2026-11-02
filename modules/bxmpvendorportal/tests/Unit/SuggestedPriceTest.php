<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Tests;

use BrainX\Marketplace\Domain\Pricing\OfferSnapshot;
use BrainX\Marketplace\Domain\Pricing\PriceComparator;
use BrainX\MarketplaceVendor\Domain\Positioning;
use BrainX\MarketplaceVendor\Domain\SuggestedPrice;
use PHPUnit\Framework\TestCase;

final class SuggestedPriceTest extends TestCase
{
    public function testNoCompetitor(): void
    {
        $r = (new SuggestedPrice())->suggest(10.0, null);
        self::assertSame('none', $r['action']);
        self::assertNull($r['suggested']);
    }

    public function testLowerToBeatCheapest(): void
    {
        $r = (new SuggestedPrice())->suggest(20.0, 5.0);
        self::assertSame('lower', $r['action']);
        self::assertSame(4.99, $r['suggested']);
        self::assertSame(-15.01, $r['delta']);
        self::assertSame(300.0, $r['delta_pct']);
    }

    public function testTieMustBeBroken(): void
    {
        $r = (new SuggestedPrice())->suggest(20.79, 20.79);
        self::assertSame('lower', $r['action']);
        self::assertSame(20.78, $r['suggested']);
    }

    public function testKeepWhenCheapestByOneStep(): void
    {
        $r = (new SuggestedPrice())->suggest(4.99, 5.0);
        self::assertSame('keep', $r['action']);
        self::assertSame(4.99, $r['suggested']);
    }

    public function testRaiseWhenWellBelow(): void
    {
        $r = (new SuggestedPrice())->suggest(5.0, 20.0);
        self::assertSame('raise', $r['action']);
        self::assertSame(19.99, $r['suggested']);
        self::assertSame(14.99, $r['delta']);
        self::assertSame(-75.0, $r['delta_pct']);
    }

    public function testFloorBlocksSuggestion(): void
    {
        $r = (new SuggestedPrice())->suggest(20.0, 10.0, floor: 12.0);
        self::assertSame('floor', $r['action']);
        self::assertNull($r['suggested']);
        $r = (new SuggestedPrice())->suggest(1.0, 0.01);
        self::assertSame('floor', $r['action']);
    }

    public function testCustomStep(): void
    {
        $r = (new SuggestedPrice(0.10))->suggest(30.0, 25.0);
        self::assertSame(24.9, $r['suggested']);
    }

    public function testInvalidStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SuggestedPrice(0.0);
    }

    private function snap(int $id, int $vendor, float $price, int $qty = 10, ?float $disc = null, string $name = ''): OfferSnapshot
    {
        return new OfferSnapshot($id, $vendor, 20, 0, $price, $qty, $disc, null, null, true, 'new', $name ?: 'V' . $vendor);
    }

    public function testPositioningWithCoreComparator(): void
    {
        $cmp = new PriceComparator();
        // client scenario Product 1: A 5, B 20, C 22
        $rows = $cmp->compare([$this->snap(1, 1, 5.0, name: 'Vendor A'), $this->snap(3, 2, 20.0, name: 'Vendor B'), $this->snap(5, 3, 22.0, name: 'Vendor C')]);
        $pos = new Positioning($cmp);

        $b = $pos->forVendor($rows, 2);
        self::assertSame(2, $b['rank']);
        self::assertSame(3, $b['sellers']);
        self::assertSame(2, $b['competitors']);
        self::assertSame(5.0, $b['best_competitor_price']);
        self::assertSame('Vendor A', $b['best_competitor_name']);
        self::assertSame(300.0, $b['delta_pct']);
        self::assertFalse($b['buy_box']);
        self::assertSame('Vendor A', $b['buy_box_vendor']);
        self::assertSame('lower', $b['suggestion']['action']);
        self::assertSame(4.99, $b['suggestion']['suggested']);

        $a = $pos->forVendor($rows, 1);
        self::assertSame(1, $a['rank']);
        self::assertTrue($a['buy_box']);
        self::assertSame(20.0, $a['best_competitor_price']);
        self::assertSame('raise', $a['suggestion']['action']);
        self::assertSame(19.99, $a['suggestion']['suggested']);
    }

    public function testPositioningUsesEffectiveDiscountPrice(): void
    {
        $cmp = new PriceComparator();
        $rows = $cmp->compare([$this->snap(1, 1, 11.07), $this->snap(2, 2, 13.33, disc: 10.50)]);
        $a = (new Positioning($cmp))->forVendor($rows, 1);
        self::assertSame(2, $a['rank']);
        self::assertSame(10.5, $a['best_competitor_price']);
        self::assertSame(10.49, $a['suggestion']['suggested']);
    }

    public function testPositioningOwnOfferMissingOrOutOfStock(): void
    {
        $cmp = new PriceComparator();
        $rows = $cmp->compare([$this->snap(1, 1, 8.0, qty: 0), $this->snap(2, 2, 9.0)]);
        $a = (new Positioning($cmp))->forVendor($rows, 1);
        self::assertNull($a['rank']);
        self::assertSame('out_of_stock', $a['reason']);
        self::assertFalse($a['buy_box']);

        $rows = $cmp->compare([$this->snap(2, 2, 9.0)]);
        $a = (new Positioning($cmp))->forVendor($rows, 1, 12.0);
        self::assertSame('inactive', $a['reason']);
        self::assertSame(12.0, $a['own_price']);
        self::assertSame(33.33, $a['delta_pct']);
        self::assertSame(8.99, $a['suggestion']['suggested']);
    }
}

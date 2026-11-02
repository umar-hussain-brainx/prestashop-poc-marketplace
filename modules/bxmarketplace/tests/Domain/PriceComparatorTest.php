<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Tests\Domain;

use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Optimizer\ShippingPolicy;
use BrainX\Marketplace\Domain\Pricing\OfferSnapshot;
use BrainX\Marketplace\Domain\Pricing\PriceComparator;
use BrainX\Marketplace\Domain\Shipping\ShippingRule;
use PHPUnit\Framework\TestCase;

final class PriceComparatorTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-02 12:00:00');
    }

    public function testDiscountWindow(): void
    {
        $pc = new PriceComparator();
        $open = new OfferSnapshot(1, 1, 10, 0, 20.0, 5, 15.0);
        $future = new OfferSnapshot(2, 1, 10, 0, 20.0, 5, 15.0, new \DateTimeImmutable('2026-12-01'));
        $past = new OfferSnapshot(3, 1, 10, 0, 20.0, 5, 15.0, null, new \DateTimeImmutable('2026-10-01'));
        $inside = new OfferSnapshot(4, 1, 10, 0, 20.0, 5, 15.0, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));
        $higher = new OfferSnapshot(5, 1, 10, 0, 20.0, 5, 25.0);

        self::assertSame(15.0, $pc->effectivePrice($open, $this->now()));
        self::assertSame(20.0, $pc->effectivePrice($future, $this->now()));
        self::assertSame(20.0, $pc->effectivePrice($past, $this->now()));
        self::assertSame(15.0, $pc->effectivePrice($inside, $this->now()));
        self::assertSame(20.0, $pc->effectivePrice($higher, $this->now()));
    }

    public function testRankingConsidersDiscountAvailabilityAndDelivery(): void
    {
        $pc = new PriceComparator();
        $offers = [
            new OfferSnapshot(1, 1, 10, 0, 10.0, 5, null, null, null, true, 'new', 'Vendor A'),
            new OfferSnapshot(2, 2, 10, 0, 12.0, 5, 8.0, null, null, true, 'new', 'Vendor B'),  // discounted -> 8
            new OfferSnapshot(3, 3, 10, 0, 5.0, 0, null, null, null, true, 'new', 'Vendor C'),  // out of stock
            new OfferSnapshot(4, 4, 10, 0, 4.0, 5, null, null, null, true, 'new', 'Vendor D'),  // no delivery to Sardinia
            new OfferSnapshot(5, 5, 10, 0, 3.0, 5, null, null, null, false, 'new', 'Vendor E'), // inactive
        ];
        $policies = [
            ShippingPolicy::flat(1, 2.0),
            ShippingPolicy::flat(2, 5.0),
            new ShippingPolicy(4, [new ShippingRule(4, 1.0), new ShippingRule(4, 0.0, null, null, null, 10, '09', true, 10)]),
        ];

        $rows = $pc->compare($offers, 1, new Destination(10, '09100'), $policies, $this->now());
        self::assertSame([1, 2], array_map(static fn ($r) => $r->offer->idOffer, array_slice($rows, 0, 2))); // A landed 12 < B 13
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->offer->idOffer] = $row;
        }
        self::assertSame(1, $rows[0]->rank);
        self::assertTrue($rows[0]->best);
        self::assertSame(2, $rows[1]->rank);
        self::assertTrue($rows[1]->onDiscount);
        self::assertSame(8.0, $rows[1]->effectivePrice);
        self::assertSame('out_of_stock', $byId[3]->ineligibleReason);
        self::assertSame('no_delivery_to_destination', $byId[4]->ineligibleReason);
        self::assertSame('inactive', $byId[5]->ineligibleReason);
        self::assertNull($byId[4]->rank);
        self::assertFalse($byId[4]->deliverable);

        // without destination: pure price ranking (B 8 < A 10)
        $rows = $pc->compare($offers, 1, null, [], $this->now());
        self::assertSame(4, $rows[0]->offer->idOffer);
        self::assertSame(2, $rows[1]->offer->idOffer);

        $pos = $pc->vendorPosition($pc->compare($offers, 1, new Destination(10, '20121'), $policies, $this->now()), 1);
        self::assertSame(2, $pos['rank']);
        self::assertSame(4.0, $pos['best_price']);
        self::assertSame(6.0, $pos['gap_to_best']);
        self::assertSame($pc->best($rows), $rows[0]);
    }

    public function testQuantityAffectsAvailability(): void
    {
        $pc = new PriceComparator();
        $rows = $pc->compare([new OfferSnapshot(1, 1, 10, 0, 10.0, 2)], 3);
        self::assertFalse($rows[0]->available);
        self::assertSame('insufficient_stock', $rows[0]->ineligibleReason);
    }

    public function testToCandidatesAndFromArray(): void
    {
        $pc = new PriceComparator();
        $snap = OfferSnapshot::fromArray(['id_offer' => '9', 'id_vendor' => '2', 'id_product' => '10', 'id_product_attribute' => '0', 'price' => '20.000000', 'discount_price' => '15.000000', 'discount_from' => '0000-00-00 00:00:00', 'discount_to' => null, 'quantity' => '4', 'active' => '1', 'condition' => 'new', 'vendor_sku' => 'SKU-9']);
        $c = $pc->toCandidates([$snap, new OfferSnapshot(10, 2, 10, 0, 1.0, 0)], $this->now());
        self::assertCount(1, $c);
        self::assertSame(15.0, $c[0]->unitPrice);
        self::assertSame(4, $c[0]->availableQuantity);
        self::assertSame('SKU-9', $c[0]->vendorSku);
    }
}

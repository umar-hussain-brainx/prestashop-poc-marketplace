<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Tests\Domain;

use BrainX\Marketplace\Domain\Optimizer\AllocationResult;
use BrainX\Marketplace\Domain\Optimizer\BasketLine;
use BrainX\Marketplace\Domain\Optimizer\CartOptimizer;
use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Optimizer\OfferCandidate;
use BrainX\Marketplace\Domain\Optimizer\ShippingPolicy;
use BrainX\Marketplace\Domain\Optimizer\UnavailableProductException;
use BrainX\Marketplace\Domain\Shipping\ShippingRule;
use BrainX\Marketplace\Domain\Shipping\ShippingRuleResolver;
use PHPUnit\Framework\TestCase;

final class CartOptimizerTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const C = 3;
    private const P1 = 101;
    private const P2 = 102;
    private const IT = 10;

    private function milan(): Destination
    {
        return new Destination(self::IT, '20121', 'IT');
    }

    /** @return array{0:BasketLine[],1:OfferCandidate[],2:ShippingPolicy[]} */
    private function clientScenario(): array
    {
        $lines = [new BasketLine(self::P1, 0, 1), new BasketLine(self::P2, 0, 1)];
        $offers = [
            new OfferCandidate(11, self::A, self::P1, 0, 5.0, 10),
            new OfferCandidate(12, self::A, self::P2, 0, 40.0, 10),
            new OfferCandidate(21, self::B, self::P1, 0, 20.0, 10),
            new OfferCandidate(22, self::B, self::P2, 0, 25.0, 10),
        ];
        $policies = [
            ShippingPolicy::flat(self::A, 5.0, 30.0, null, null, 'Vendor A'),
            ShippingPolicy::flat(self::B, 5.0, 20.0, null, null, 'Vendor B'),
        ];

        return [$lines, $offers, $policies];
    }

    /** @return array<int, array<string,int>> vendor => ["product-attr" => qty] */
    private function allocationMap(AllocationResult $r): array
    {
        $map = [];
        foreach ($r->vendors as $v) {
            foreach ($v->lines as $l) {
                $k = $l->idProduct . '-' . $l->idProductAttribute;
                $map[$v->idVendor][$k] = ($map[$v->idVendor][$k] ?? 0) + $l->quantity;
            }
        }
        ksort($map);

        return $map;
    }

    public function testClientReferenceScenarioPicksThe35EuroSplit(): void
    {
        [$lines, $offers, $policies] = $this->clientScenario();
        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());

        self::assertEqualsWithDelta(35.0, $r->total(), 0.0001);
        self::assertEqualsWithDelta(30.0, $r->itemsTotal, 0.0001);
        self::assertEqualsWithDelta(5.0, $r->shippingTotal, 0.0001);
        self::assertSame([self::A => [self::P1 . '-0' => 1], self::B => [self::P2 . '-0' => 1]], $this->allocationMap($r));
        self::assertSame(AllocationResult::MODE_EXACT, $r->mode);
        self::assertTrue($r->provenOptimal);

        $a = $r->getVendor(self::A);
        $b = $r->getVendor(self::B);
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertEqualsWithDelta(5.0, $a->shippingCost, 0.0001);
        self::assertSame(ShippingRuleResolver::TIER_BASE, $a->shippingTier);
        self::assertEqualsWithDelta(0.0, $b->shippingCost, 0.0001);
        self::assertSame(ShippingRuleResolver::TIER_FREE, $b->shippingTier);

        // the alternatives of the client table are reported
        $alts = [];
        foreach ($r->alternatives as $alt) {
            $alts[$alt['label']] = $alt['total'];
        }
        self::assertEqualsWithDelta(45.0, $alts['Everything from Vendor A'], 0.0001);
        self::assertEqualsWithDelta(45.0, $alts['Everything from Vendor B'], 0.0001);
        self::assertEqualsWithDelta(35.0, $alts['Optimal allocation (selected)'], 0.0001);
        self::assertStringContainsString('Saves €10.00 vs. the best single-vendor order', implode("\n", $r->explanation));

        $arr = $r->toArray();
        self::assertSame(35.0, $arr['total']);
        self::assertCount(2, $arr['vendors']);
    }

    public function testClientScenarioUnaffectedByWorseThirdVendor(): void
    {
        [$lines, $offers, $policies] = $this->clientScenario();
        $offers[] = new OfferCandidate(31, self::C, self::P1, 0, 22.0, 10);
        $offers[] = new OfferCandidate(32, self::C, self::P2, 0, 45.0, 10);
        $policies[] = new ShippingPolicy(self::C, [
            new ShippingRule(self::C, 6.90, 60.0, 25.0, 2.90),
            new ShippingRule(self::C, 0.0, null, null, null, self::IT, '09', true, 10),
        ], 'Vendor C');

        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        self::assertEqualsWithDelta(35.0, $r->total(), 0.0001);
        self::assertSame([self::A, self::B], array_map(static fn ($v) => $v->idVendor, $r->vendors));
    }

    public function testDuplicateLinesAreMerged(): void
    {
        [, $offers, $policies] = $this->clientScenario();
        $lines = [new BasketLine(self::P1, 0, 1), new BasketLine(self::P2, 0, 1)];
        $r1 = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        $r2 = (new CartOptimizer())->optimize([new BasketLine(self::P1, 0, 1), new BasketLine(self::P1, 0, 1)], $offers, $policies, $this->milan());
        self::assertEqualsWithDelta(35.0, $r1->total(), 0.0001);
        // 2×P1: A 10 + 5 shipping = 15 ; B 40 free = 40 ; split A1+B1 = 5+5+20 = 30 -> 15 is best
        self::assertEqualsWithDelta(15.0, $r2->total(), 0.0001);
        self::assertSame([self::A => [self::P1 . '-0' => 2]], $this->allocationMap($r2));
    }

    public function testQuantitySplitAcrossVendorsUnderStockLimits(): void
    {
        // 5 units wanted, cheapest vendor only has 3 in stock
        $lines = [new BasketLine(self::P1, 0, 5)];
        $offers = [
            new OfferCandidate(1, self::A, self::P1, 0, 10.0, 3),
            new OfferCandidate(2, self::B, self::P1, 0, 12.0, 10),
            new OfferCandidate(3, self::C, self::P1, 0, 11.0, 1),
        ];
        $policies = [
            ShippingPolicy::flat(self::A, 4.0),
            ShippingPolicy::flat(self::B, 4.0),
            ShippingPolicy::flat(self::C, 9.0),
        ];
        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        // A3 (30+4) + B2 (24+4) = 62 ; A3+C1+B1 = 30+4+11+9+12+4 = 70 ; B5 = 64
        self::assertEqualsWithDelta(62.0, $r->total(), 0.0001);
        self::assertSame([self::A => [self::P1 . '-0' => 3], self::B => [self::P1 . '-0' => 2]], $this->allocationMap($r));
        foreach ($r->lines() as $l) {
            self::assertLessThanOrEqual($l->idVendor === self::A ? 3 : 10, $l->quantity);
        }
        self::assertTrue($r->provenOptimal);
    }

    public function testSplitIsAvoidedWhenConsolidationIsCheaper(): void
    {
        $lines = [new BasketLine(self::P1, 0, 4)];
        $offers = [
            new OfferCandidate(1, self::A, self::P1, 0, 10.0, 2),
            new OfferCandidate(2, self::B, self::P1, 0, 11.0, 10),
        ];
        $policies = [ShippingPolicy::flat(self::A, 6.0), ShippingPolicy::flat(self::B, 6.0, 40.0)];
        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        // B4 = 44 free ; A2+B2 = 20+6 + 22+6 = 54
        self::assertEqualsWithDelta(44.0, $r->total(), 0.0001);
        self::assertSame([self::B => [self::P1 . '-0' => 4]], $this->allocationMap($r));
    }

    public function testPostcodeExclusionRemovesVendor(): void
    {
        $lines = [new BasketLine(self::P1, 0, 1)];
        $offers = [
            new OfferCandidate(1, self::C, self::P1, 0, 1.0, 10), // cheapest but excluded for Sardinia (09xxx)
            new OfferCandidate(2, self::A, self::P1, 0, 8.0, 10),
        ];
        $policies = [
            new ShippingPolicy(self::C, [
                new ShippingRule(self::C, 2.0),
                new ShippingRule(self::C, 0.0, null, null, null, self::IT, '09', true, 10),
            ], 'Vendor C'),
            ShippingPolicy::flat(self::A, 5.0, null, null, null, 'Vendor A'),
        ];
        $opt = new CartOptimizer();

        $cagliari = $opt->optimize($lines, $offers, $policies, new Destination(self::IT, '09124'));
        self::assertEqualsWithDelta(13.0, $cagliari->total(), 0.0001);
        self::assertSame([self::A], array_map(static fn ($v) => $v->idVendor, $cagliari->vendors));
        self::assertSame([['id_offer' => 1, 'id_vendor' => self::C, 'reason' => 'no_delivery_to_destination']], $cagliari->excludedOffers);

        $milan = $opt->optimize($lines, $offers, $policies, $this->milan());
        self::assertEqualsWithDelta(3.0, $milan->total(), 0.0001);
        self::assertSame([self::C], array_map(static fn ($v) => $v->idVendor, $milan->vendors));
    }

    public function testCountryRestrictedVendorDoesNotDeliverElsewhere(): void
    {
        $lines = [new BasketLine(self::P1, 0, 1)];
        $offers = [new OfferCandidate(1, self::A, self::P1, 0, 1.0, 10), new OfferCandidate(2, self::B, self::P1, 0, 9.0, 10)];
        $policies = [
            new ShippingPolicy(self::A, [new ShippingRule(self::A, 1.0, null, null, null, self::IT)]),
            ShippingPolicy::flat(self::B, 1.0),
        ];
        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, new Destination(8, '75001'));
        self::assertSame([self::B], array_map(static fn ($v) => $v->idVendor, $r->vendors));
    }

    public function testReducedShippingThresholdIsExploited(): void
    {
        // C: €6.90, €2.90 from €25, free from €60.
        $lines = [new BasketLine(self::P1, 0, 1), new BasketLine(self::P2, 0, 1)];
        $offers = [
            new OfferCandidate(1, self::C, self::P1, 0, 12.0, 5),
            new OfferCandidate(2, self::C, self::P2, 0, 14.0, 5),
            new OfferCandidate(3, self::A, self::P2, 0, 11.0, 5),
        ];
        $policies = [
            ShippingPolicy::flat(self::C, 6.90, 60.0, 25.0, 2.90, 'Vendor C'),
            ShippingPolicy::flat(self::A, 6.0, null, null, null, 'Vendor A'),
        ];
        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        // all C: 26 + 2.90 = 28.90 ; C P1 + A P2: 12+6.90 + 11+6 = 35.90
        self::assertEqualsWithDelta(28.90, $r->total(), 0.0001);
        $c = $r->getVendor(self::C);
        self::assertNotNull($c);
        self::assertSame(ShippingRuleResolver::TIER_REDUCED, $c->shippingTier);
        self::assertEqualsWithDelta(2.90, $c->shippingCost, 0.0001);
    }

    public function testBuyingMoreExpensiveOfferToReachFreeShippingThreshold(): void
    {
        $lines = [new BasketLine(self::P1, 0, 1), new BasketLine(self::P2, 0, 1)];
        $offers = [
            new OfferCandidate(1, self::A, self::P1, 0, 18.0, 5),
            new OfferCandidate(2, self::A, self::P2, 0, 13.0, 5), // reaches €31 ≥ €30 -> free
            new OfferCandidate(3, self::B, self::P2, 0, 10.0, 5),
        ];
        $policies = [ShippingPolicy::flat(self::A, 7.0, 30.0), ShippingPolicy::flat(self::B, 7.0)];
        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        self::assertEqualsWithDelta(31.0, $r->total(), 0.0001);
        self::assertSame([self::A => [self::P1 . '-0' => 1, self::P2 . '-0' => 1]], $this->allocationMap($r));
    }

    public function testUnavailableProductThrows(): void
    {
        [$lines, $offers, $policies] = $this->clientScenario();
        $lines[] = new BasketLine(999, 0, 1);
        try {
            (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
            self::fail('Expected UnavailableProductException');
        } catch (UnavailableProductException $e) {
            self::assertSame([['id_product' => 999, 'id_product_attribute' => 0, 'requested' => 1, 'available' => 0]], $e->lines);
        }
    }

    public function testInsufficientStockThrowsAndPartialModeAllocatesWhatExists(): void
    {
        $lines = [new BasketLine(self::P1, 0, 5), new BasketLine(self::P2, 0, 1)];
        $offers = [new OfferCandidate(1, self::A, self::P1, 0, 5.0, 2), new OfferCandidate(2, self::B, self::P1, 0, 6.0, 1), new OfferCandidate(3, self::A, self::P2, 0, 30.0, 1)];
        $policies = [ShippingPolicy::flat(self::A, 5.0, 30.0), ShippingPolicy::flat(self::B, 5.0, 20.0)];

        $this->expectException(UnavailableProductException::class);
        try {
            (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        } finally {
            $partial = (new CartOptimizer(allowPartial: true))->optimize($lines, $offers, $policies, $this->milan());
            self::assertFalse($partial->isComplete());
            self::assertSame(3, $partial->unavailable[0]['available']);
            $qty = array_sum(array_map(static fn ($l) => $l->idProduct === self::P1 ? $l->quantity : 0, $partial->lines()));
            self::assertSame(3, $qty);
        }
    }

    public function testNeverSubstitutesAnotherVariantOrProduct(): void
    {
        $lines = [new BasketLine(self::P1, 7, 1)]; // variant 7 requested
        $offers = [
            new OfferCandidate(1, self::A, self::P1, 8, 1.0, 10), // other variant, cheaper -> must be ignored
            new OfferCandidate(2, self::A, self::P1, 0, 1.0, 10), // base product -> must be ignored
            new OfferCandidate(3, self::B, self::P1, 7, 9.0, 10),
        ];
        $r = (new CartOptimizer())->optimize($lines, $offers, [ShippingPolicy::flat(self::A, 0.0), ShippingPolicy::flat(self::B, 0.0)], $this->milan());
        self::assertSame([self::B => [self::P1 . '-7' => 1]], $this->allocationMap($r));

        $this->expectException(UnavailableProductException::class);
        (new CartOptimizer())->optimize([new BasketLine(self::P1, 9, 1)], $offers, [], $this->milan());
    }

    public function testOutOfStockOffersAreIgnoredAndVendorWithoutPolicyShipsFree(): void
    {
        $lines = [new BasketLine(self::P1, 0, 1)];
        $offers = [new OfferCandidate(1, self::A, self::P1, 0, 1.0, 0), new OfferCandidate(2, self::B, self::P1, 0, 3.0, 4)];
        $r = (new CartOptimizer())->optimize($lines, $offers, [], $this->milan());
        self::assertEqualsWithDelta(3.0, $r->total(), 0.0001);
        self::assertSame('out_of_stock', $r->excludedOffers[0]['reason']);
    }

    public function testEmptyBasket(): void
    {
        $r = (new CartOptimizer())->optimize([], [], [], $this->milan());
        self::assertSame(0.0, $r->total());
        self::assertSame([], $r->vendors);
    }

    public function testBlendedUnitPrices(): void
    {
        $lines = [new BasketLine(self::P1, 0, 5)];
        $offers = [new OfferCandidate(1, self::A, self::P1, 0, 10.0, 3), new OfferCandidate(2, self::B, self::P1, 0, 12.0, 10)];
        $r = (new CartOptimizer())->optimize($lines, $offers, [], $this->milan());
        self::assertEqualsWithDelta((30 + 24) / 5, $r->blendedUnitPrices()[self::P1 . '-0'], 0.0001);
    }

    /**
     * Randomised cross-check of the exact branch-and-bound against an exhaustive brute force.
     */
    public function testExactSearchMatchesBruteForceOnRandomBaskets(): void
    {
        mt_srand(42);
        for ($case = 0; $case < 120; ++$case) {
            [$lines, $offers, $policies] = $this->randomInstance(mt_rand(1, 4), mt_rand(2, 3), 3);
            try {
                $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
            } catch (UnavailableProductException) {
                continue;
            }
            $brute = $this->bruteForce($lines, $offers, $policies);
            self::assertTrue($r->provenOptimal);
            self::assertEqualsWithDelta($brute, $r->total(), 0.0001, 'case ' . $case);
            $this->assertConsistent($r, $lines, $offers);
        }
    }

    /** The heuristic alone (exact disabled) must stay close to the optimum and never be infeasible. */
    public function testHeuristicQualityAgainstExact(): void
    {
        mt_srand(7);
        $worst = 0.0;
        $equal = 0;
        $n = 0;
        for ($case = 0; $case < 60; ++$case) {
            [$lines, $offers, $policies] = $this->randomInstance(mt_rand(3, 6), mt_rand(2, 4), 3);
            try {
                $exact = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
            } catch (UnavailableProductException) {
                continue;
            }
            $heur = (new CartOptimizer(exactMaxLines: 0))->optimize($lines, $offers, $policies, $this->milan());
            self::assertSame(AllocationResult::MODE_HEURISTIC, $heur->mode);
            self::assertGreaterThanOrEqual($exact->total() - 0.0001, $heur->total());
            $this->assertConsistent($heur, $lines, $offers);
            $gap = ($heur->total() - $exact->total()) / max(0.01, $exact->total());
            $worst = max($worst, $gap);
            $equal += $gap < 1e-9 ? 1 : 0;
            ++$n;
        }
        self::assertGreaterThan(0, $n);
        self::assertLessThan(0.05, $worst, 'heuristic worst gap above 5%');
        self::assertGreaterThanOrEqual((int) floor($n * 0.9), $equal, 'heuristic should find the optimum in ≥90% of small cases');
    }

    public function testLargeBasketPerformance30LinesX5Vendors(): void
    {
        mt_srand(2024);
        $vendors = [1, 2, 3, 4, 5];
        $lines = [];
        $offers = [];
        $id = 1;
        for ($p = 1; $p <= 30; ++$p) {
            $lines[] = new BasketLine(1000 + $p, 0, mt_rand(1, 4));
            $base = mt_rand(300, 6000) / 100;
            foreach ($vendors as $v) {
                if (mt_rand(1, 100) <= 85) {
                    $offers[] = new OfferCandidate($id++, $v, 1000 + $p, 0, round($base * mt_rand(85, 125) / 100, 2), mt_rand(1, 6));
                }
            }
            $offers[] = new OfferCandidate($id++, 1, 1000 + $p, 0, round($base * 1.3, 2), 50); // guarantee availability
        }
        $policies = [
            ShippingPolicy::flat(1, 5.0, 30.0),
            ShippingPolicy::flat(2, 5.0, 20.0),
            ShippingPolicy::flat(3, 6.90, 60.0, 25.0, 2.90),
            ShippingPolicy::flat(4, 9.90, 150.0),
            ShippingPolicy::flat(5, 4.50),
        ];

        $start = microtime(true);
        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        $elapsed = microtime(true) - $start;

        self::assertLessThan(3.0, $elapsed, 'optimizer too slow for 30 lines x 5 vendors');
        self::assertSame(AllocationResult::MODE_HEURISTIC, $r->mode);
        $this->assertConsistent($r, $lines, $offers);
        foreach ($r->alternatives as $alt) {
            if ($alt['feasible']) {
                self::assertLessThanOrEqual($alt['total'] + 0.0001, $r->total(), $alt['label']);
            }
        }
    }

    public function testMediumBasketIsSolvedExactlyWithinBudget(): void
    {
        mt_srand(99);
        [$lines, $offers, $policies] = $this->randomInstance(10, 5, 2, 100);
        $start = microtime(true);
        $r = (new CartOptimizer())->optimize($lines, $offers, $policies, $this->milan());
        self::assertLessThan(5.0, microtime(true) - $start);
        $this->assertConsistent($r, $lines, $offers);
        self::assertSame(AllocationResult::MODE_EXACT, $r->mode, implode(' ', $r->explanation));
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0:BasketLine[],1:OfferCandidate[],2:ShippingPolicy[]} */
    private function randomInstance(int $nLines, int $nVendors, int $maxQty, int $stockMax = 4): array
    {
        $lines = [];
        $offers = [];
        $id = 1;
        for ($p = 1; $p <= $nLines; ++$p) {
            $lines[] = new BasketLine($p, 0, mt_rand(1, $maxQty));
            for ($v = 1; $v <= $nVendors; ++$v) {
                if (mt_rand(1, 100) <= 75) {
                    $offers[] = new OfferCandidate($id++, $v, $p, 0, mt_rand(100, 4000) / 100, mt_rand(1, $stockMax));
                }
            }
        }
        $policies = [];
        for ($v = 1; $v <= $nVendors; ++$v) {
            $base = mt_rand(0, 900) / 100;
            $free = mt_rand(0, 3) > 0 ? (float) mt_rand(15, 80) : null;
            $redT = mt_rand(0, 2) === 0 ? (float) mt_rand(10, 40) : null;
            $policies[] = ShippingPolicy::flat($v, $base, $free, $redT, $redT !== null ? round($base / 2, 2) : null);
        }

        return [$lines, $offers, $policies];
    }

    /** Exhaustive minimum over every split of every line. */
    private function bruteForce(array $lines, array $offers, array $policies): float
    {
        $rules = [];
        foreach ($policies as $p) {
            $rules[$p->idVendor] = $p->rules[0];
        }
        $perLine = [];
        foreach ($lines as $l) {
            $cands = array_values(array_filter($offers, static fn ($o) => $o->idProduct === $l->idProduct && $o->idProductAttribute === $l->idProductAttribute && $o->availableQuantity > 0));
            $splits = [];
            $this->splits($cands, 0, $l->quantity, [], $splits);
            $perLine[] = $splits;
        }
        $best = INF;
        $walk = function (int $i, array $sub) use (&$walk, &$best, $perLine, $rules): void {
            if ($i === count($perLine)) {
                $t = 0.0;
                foreach ($sub as $v => $s) {
                    $t += $s + ShippingRuleResolver::cost($rules[$v], $s);
                }
                $best = min($best, $t);

                return;
            }
            foreach ($perLine[$i] as $split) {
                $n = $sub;
                foreach ($split as $v => $amt) {
                    $n[$v] = ($n[$v] ?? 0.0) + $amt;
                }
                $walk($i + 1, $n);
            }
        };
        $walk(0, []);

        return $best;
    }

    private function splits(array $cands, int $k, int $remaining, array $acc, array &$out): void
    {
        if ($remaining === 0) {
            $out[] = $acc;

            return;
        }
        if ($k === count($cands)) {
            return;
        }
        $o = $cands[$k];
        for ($q = 0; $q <= min($remaining, $o->availableQuantity); ++$q) {
            $n = $acc;
            if ($q > 0) {
                $n[$o->idVendor] = ($n[$o->idVendor] ?? 0.0) + $q * $o->unitPrice;
            }
            $this->splits($cands, $k + 1, $remaining - $q, $n, $out);
        }
    }

    private function assertConsistent(AllocationResult $r, array $lines, array $offers): void
    {
        $byOffer = [];
        foreach ($offers as $o) {
            $byOffer[$o->idOffer] = $o;
        }
        $want = [];
        foreach ($lines as $l) {
            $want[$l->key()] = ($want[$l->key()] ?? 0) + $l->quantity;
        }
        $got = [];
        $items = 0.0;
        foreach ($r->lines() as $l) {
            $o = $byOffer[$l->idOffer];
            self::assertSame($o->idProduct, $l->idProduct);
            self::assertSame($o->idProductAttribute, $l->idProductAttribute);
            self::assertSame($o->idVendor, $l->idVendor);
            self::assertLessThanOrEqual($o->availableQuantity, $l->quantity);
            $got[$l->idProduct . '-' . $l->idProductAttribute] = ($got[$l->idProduct . '-' . $l->idProductAttribute] ?? 0) + $l->quantity;
            $items += $l->total();
        }
        ksort($want);
        ksort($got);
        self::assertSame($want, $got);
        self::assertEqualsWithDelta($items, $r->itemsTotal, 0.0001);
    }
}

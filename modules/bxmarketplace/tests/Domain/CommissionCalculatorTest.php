<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Tests\Domain;

use BrainX\Marketplace\Domain\Commission\CommissionCalculator;
use BrainX\Marketplace\Domain\Commission\CommissionTerms;
use PHPUnit\Framework\TestCase;

final class CommissionCalculatorTest extends TestCase
{
    public function testLineCommissionPercentAndFixed(): void
    {
        $c = new CommissionCalculator();
        self::assertSame(0.5, $c->lineCommission(5.0, 1, new CommissionTerms(10)));
        self::assertSame(3.13, $c->lineCommission(25.0, 1, new CommissionTerms(12.5))); // 3.125 -> 3.13 half-up
        self::assertSame(2.1, $c->lineCommission(10.0, 2, new CommissionTerms(8, 0.5)));
        self::assertSame(0.3, $c->lineCommission(0.3, 1, new CommissionTerms(8, 0.5)), 'capped at line value');
        self::assertSame(0.0, $c->lineCommission(0.0, 3, new CommissionTerms(8, 0.5)));
    }

    public function testOrderCommissionAndPayout(): void
    {
        $c = new CommissionCalculator();
        $oc = $c->calculateOrder([
            ['unit_price' => 5.0, 'quantity' => 1],
            ['unit_price' => '12.30', 'quantity' => '3'],
        ], 5.0, new CommissionTerms(10, 0.0));
        self::assertSame(41.9, $oc->productsTotal);
        self::assertSame(4.19, $oc->commission); // 0.50 + 3.69
        self::assertSame(46.9, $oc->totalPaid());
        self::assertSame(42.71, $oc->vendorPayout());
        self::assertSame([0.5, 3.69], $oc->lineCommissions);

        $withShip = $c->calculateOrder([['unit_price' => 100, 'quantity' => 1]], 10.0, new CommissionTerms(10, 0.0, true));
        self::assertSame(11.0, $withShip->commission);
    }

    public function testSettlementWithAdjustmentsAndCancelledOrders(): void
    {
        $c = new CommissionCalculator();
        $s = $c->settle([
            ['total_paid' => '100.00', 'commission_amount' => '10.00', 'status' => 'delivered'],
            ['total_paid' => '50.00', 'commission_amount' => '5.00', 'status' => 'shipped'],
            ['total_paid' => '999.00', 'commission_amount' => '99.00', 'status' => 'cancelled'],
        ], [['amount' => '-2.50'], 1.0]);
        self::assertSame(150.0, $s->gross);
        self::assertSame(15.0, $s->commission);
        self::assertSame(-1.5, $s->adjustments);
        self::assertSame(133.5, $s->netPayout());
        self::assertSame(2, $s->ordersCount);
    }

    public function testPaymentSplitForClientScenario(): void
    {
        $c = new CommissionCalculator();
        $a = $c->calculateOrder([['unit_price' => 5.0, 'quantity' => 1]], 5.0, new CommissionTerms(10));
        $b = $c->calculateOrder([['unit_price' => 25.0, 'quantity' => 1]], 0.0, new CommissionTerms(12.5));
        $split = $c->splitPayment([1 => $a, 2 => $b]);
        self::assertSame(35.0, $split['total']);
        self::assertSame(3.63, $split['platform']); // 0.50 + 3.13
        self::assertSame([1 => 9.5, 2 => 21.87], $split['vendors']);
        self::assertEqualsWithDelta($split['total'], array_sum($split['vendors']) + $split['platform'], 0.001);
    }

    public function testInvalidTerms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CommissionTerms(120);
    }
}

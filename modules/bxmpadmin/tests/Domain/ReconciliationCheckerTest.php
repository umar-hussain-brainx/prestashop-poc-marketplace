<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Tests\Domain;

use BrainX\MarketplaceAdmin\Domain\ReconciliationChecker;
use PHPUnit\Framework\TestCase;

final class ReconciliationCheckerTest extends TestCase
{
    private static function vo(int $id, int $vendor, float $products, float $shipping, float $commission, ?string $ref = 'TR', string $status = 'delivered', array $extra = []): array
    {
        return $extra + ['id_vendor_order' => $id, 'id_order' => 100 + $id, 'id_vendor' => $vendor, 'reference' => 'VO' . $id, 'status' => $status,
            'total_products' => $products, 'total_shipping' => $shipping, 'total_paid' => $products + $shipping,
            'commission_amount' => $commission, 'vendor_payout' => $products + $shipping - $commission, 'payment_transfer_ref' => $ref];
    }

    private static function codes(array $r): array
    {
        return array_column($r['issues'], 'code');
    }

    public function testCleanDataHasNoIssueAndSums(): void
    {
        $r = (new ReconciliationChecker())->check(
            [self::vo(1, 1, 40, 5, 4), self::vo(2, 1, 20, 0, 2), self::vo(3, 2, 25, 0, 3.13)],
            [['id_vendor' => 1, 'amount' => -5], ['id_vendor' => 2, 'amount' => 2.5]]
        );
        self::assertSame([], $r['issues']);
        self::assertSame(65.0, $r['vendors'][1]['gross']);
        self::assertSame(6.0, $r['vendors'][1]['commission']);
        self::assertSame(59.0, $r['vendors'][1]['payout']);
        self::assertSame(54.0, $r['vendors'][1]['net_due']);
        self::assertSame(24.37, $r['vendors'][2]['net_due']);
        self::assertSame(90.0, $r['totals']['gross']);
    }

    public function testFlagsMismatches(): void
    {
        $bad = self::vo(4, 1, 10, 0, 1);
        $bad['vendor_payout'] = 8.0; // 10 - 1 != 8
        $r = (new ReconciliationChecker())->check([
            self::vo(1, 1, 10, 0, 1, null),                                   // missing ref
            self::vo(2, 1, 10, 0, 1, 'TR-2', 'cancelled'),                    // cancelled but transferred
            self::vo(3, 2, 10, 0, 1, null, 'cancelled'),                      // cancelled still carrying payout
            $bad,
            self::vo(5, 2, 100, 0, 10, 'TR', 'shipped', ['expected_commission' => 12.5]),
        ]);
        $codes = self::codes($r);
        foreach (['missing_transfer_ref', 'cancelled_transferred', 'cancelled_with_payout', 'payout_mismatch', 'commission_drift'] as $c) {
            self::assertContains($c, $codes);
        }
        self::assertSame('error', $r['issues'][0]['severity']); // errors sorted first
        self::assertSame(1, $r['vendors'][1]['cancelled']);
        self::assertSame(9.0, $r['vendors'][1]['untransferred']);
    }

    public function testParentOrderAndSettlementChecks(): void
    {
        $r = (new ReconciliationChecker())->check(
            [self::vo(1, 1, 10, 0, 1, 'T', 'delivered', ['id_order' => 7, 'parent_total' => 25.0]),
             self::vo(2, 2, 15, 0, 1, 'T', 'delivered', ['id_order' => 7, 'parent_total' => 25.0]),
             self::vo(3, 2, 30, 0, 3, 'T', 'delivered', ['id_order' => 8, 'parent_total' => 35.0])],
            [],
            [['id_settlement' => 9, 'id_vendor' => 1, 'status' => 'paid', 'gross' => 10, 'commission' => 1, 'adjustments' => 0, 'net_payout' => 9,
              'computed' => ['gross' => 10, 'commission' => 1, 'adjustments' => 0, 'net_payout' => 9]],
             ['id_settlement' => 10, 'id_vendor' => 2, 'status' => 'approved', 'gross' => 40, 'commission' => 4, 'adjustments' => 0, 'net_payout' => 36,
              'computed' => ['gross' => 45, 'commission' => 4, 'adjustments' => 0, 'net_payout' => 41]]]
        );
        $parent = array_values(array_filter($r['issues'], fn ($i) => $i['code'] === 'parent_order_mismatch'));
        self::assertCount(1, $parent);
        self::assertSame(8, $parent[0]['id_order']);
        $stale = array_values(array_filter($r['issues'], fn ($i) => $i['code'] === 'settlement_stale'));
        self::assertCount(1, $stale);
        self::assertSame('error', $stale[0]['severity']);
        self::assertSame(10, $stale[0]['id_settlement']);
    }
}

<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Tests;

use BrainX\Marketplace\Domain\Commission\CommissionTerms;
use BrainX\MarketplaceCheckout\Order\VendorOrderPlanner;
use PHPUnit\Framework\TestCase;

final class VendorOrderPlannerTest extends TestCase
{
    private function terms(): array
    {
        return [1 => new CommissionTerms(10.0), 2 => new CommissionTerms(12.5), 3 => new CommissionTerms(8.0, 0.5)];
    }

    public function testClientScenarioSplit(): void
    {
        $rows = [
            ['id_vendor' => 2, 'id_offer' => 4, 'id_product' => 21, 'id_product_attribute' => 0, 'quantity' => 1, 'unit_price' => '25.000000', 'product_name' => 'Product 2'],
            ['id_vendor' => 1, 'id_offer' => 1, 'id_product' => 20, 'id_product_attribute' => 0, 'quantity' => 1, 'unit_price' => '5.000000', 'product_name' => 'Product 1', 'vendor_sku' => 'A-P1'],
        ];
        $plans = (new VendorOrderPlanner())->plan('XKBKNABJK', $rows, [1 => 5.0, 2 => 0.0], $this->terms(), [1 => ['name' => 'Vendor A', 'payout_account' => 'w-a']]);

        self::assertSame([1, 2], array_keys($plans));
        $a = $plans[1];
        self::assertSame('XKBKNABJK-V1', $a->reference);
        self::assertSame(5.0, $a->totalProducts);
        self::assertSame(5.0, $a->totalShipping);
        self::assertSame(10.0, $a->totalPaid());
        self::assertSame(0.5, $a->commission);
        self::assertSame(9.5, $a->vendorPayout());
        self::assertSame('A-P1', $a->lines[0]['vendor_sku']);
        self::assertSame('w-a', $a->walletId);
        $b = $plans[2];
        self::assertSame('XKBKNABJK-V2', $b->reference);
        self::assertSame(25.0, $b->totalPaid());
        self::assertSame(3.13, $b->commission); // 12.5 % of 25 = 3.125 → half-up
        self::assertSame(21.87, $b->vendorPayout());
        self::assertSame(3.13, $b->lines[0]['commission_amount']);
    }

    public function testQuantitySplitAndFixedFee(): void
    {
        $rows = [
            ['id_vendor' => 1, 'id_offer' => 9, 'id_product' => 1, 'id_product_attribute' => 2, 'quantity' => 2, 'unit_price' => 26.77],
            ['id_vendor' => 2, 'id_offer' => 10, 'id_product' => 1, 'id_product_attribute' => 2, 'quantity' => 3, 'unit_price' => 22.23],
            ['id_vendor' => 3, 'id_offer' => 25, 'id_product' => 2, 'id_product_attribute' => 9, 'quantity' => 1, 'unit_price' => 33.75],
        ];
        $plans = (new VendorOrderPlanner())->plan('REF', $rows, [3 => 2.9], $this->terms());
        self::assertSame(53.54, $plans[1]->totalProducts);
        self::assertSame(5.35, $plans[1]->commission);
        self::assertSame(66.69, $plans[2]->totalProducts);
        self::assertSame(8.34, $plans[2]->commission);
        // C: 8 % of 33.75 = 2.70 + 0.50 fixed per line; commission not applied on shipping by default
        self::assertSame(3.2, $plans[3]->commission);
        self::assertSame(36.65, $plans[3]->totalPaid());
        self::assertSame(33.45, $plans[3]->vendorPayout());
        self::assertSame(120.23, round($plans[1]->totalProducts + $plans[2]->totalProducts, 2));
    }

    public function testCommissionOnShippingWhenConfigured(): void
    {
        $rows = [['id_vendor' => 1, 'id_offer' => 1, 'id_product' => 20, 'id_product_attribute' => 0, 'quantity' => 1, 'unit_price' => 5]];
        $plans = (new VendorOrderPlanner())->plan('R', $rows, [1 => 5.0], [1 => new CommissionTerms(10.0, 0, true)]);
        self::assertSame(1.0, $plans[1]->commission);
    }

    public function testUnknownVendorTermsMeanNoCommissionAndZeroQtyIgnored(): void
    {
        $rows = [
            ['id_vendor' => 7, 'id_offer' => 1, 'id_product' => 20, 'id_product_attribute' => 0, 'quantity' => 2, 'unit_price' => 5],
            ['id_vendor' => 8, 'id_offer' => 2, 'id_product' => 21, 'id_product_attribute' => 0, 'quantity' => 0, 'unit_price' => 5],
        ];
        $plans = (new VendorOrderPlanner())->plan('R', $rows, [], []);
        self::assertSame([7], array_keys($plans));
        self::assertSame(0.0, $plans[7]->commission);
        self::assertSame(10.0, $plans[7]->vendorPayout());
    }

    public function testQuantityMismatches(): void
    {
        $rows = [
            ['id_product' => 1, 'id_product_attribute' => 2, 'quantity' => 2],
            ['id_product' => 1, 'id_product_attribute' => 2, 'quantity' => 3],
            ['id_product' => 20, 'id_product_attribute' => 0, 'quantity' => 1],
        ];
        self::assertSame([], VendorOrderPlanner::quantityMismatches($rows, ['1-2' => 5, '20-0' => 1]));
        self::assertSame(['1-2' => ['allocated' => 5, 'ordered' => 6], '21-0' => ['allocated' => 0, 'ordered' => 1]],
            VendorOrderPlanner::quantityMismatches($rows, ['1-2' => 6, '20-0' => 1, '21-0' => 1]));
    }
}

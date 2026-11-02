<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Tests;

use BrainX\MarketplaceVendor\Domain\OfferInput;
use BrainX\MarketplaceVendor\Domain\OrderWorkflow;
use BrainX\MarketplaceVendor\Domain\Period;
use BrainX\MarketplaceVendor\Domain\ShippingRuleInput;
use PHPUnit\Framework\TestCase;

final class InputAndWorkflowTest extends TestCase
{
    public function testWorkflowTransitions(): void
    {
        self::assertSame(['accept', 'cancel'], OrderWorkflow::availableActions('pending'));
        self::assertSame(['ship', 'cancel'], OrderWorkflow::availableActions('accepted'));
        self::assertSame(['deliver'], OrderWorkflow::availableActions('shipped'));
        self::assertSame([], OrderWorkflow::availableActions('delivered'));
        self::assertSame([], OrderWorkflow::availableActions('cancelled'));
        self::assertFalse(OrderWorkflow::canApply('ship', 'pending'));
        self::assertFalse(OrderWorkflow::canApply('cancel', 'shipped'));
        self::assertSame('invalid_transition', OrderWorkflow::validate('deliver', 'accepted')['error']);
        self::assertSame('unknown_action', OrderWorkflow::validate('refund', 'accepted')['error']);
    }

    public function testShipRequiresTrackingAndCarrier(): void
    {
        self::assertSame('invalid_tracking_number', OrderWorkflow::validate('ship', 'accepted', ['carrier_name' => 'BRT'])['error']);
        self::assertSame('invalid_tracking_number', OrderWorkflow::validate('ship', 'accepted', ['tracking_number' => '<script>', 'carrier_name' => 'BRT'])['error']);
        self::assertSame('invalid_carrier', OrderWorkflow::validate('ship', 'accepted', ['tracking_number' => 'TRK123456'])['error']);
        $ok = OrderWorkflow::validate('ship', 'accepted', ['tracking_number' => ' 1Z999AA10123456784 ', 'carrier_name' => 'UPS']);
        self::assertTrue($ok['ok']);
        self::assertSame('shipped', $ok['to']);
        self::assertSame('1Z999AA10123456784', $ok['tracking_number']);
    }

    public function testOfferInput(): void
    {
        $ok = OfferInput::validate(['price' => '12,50', 'discount_price' => '10', 'discount_from' => '2026-11-01', 'discount_to' => '2026-11-30T23:59', 'quantity' => '7', 'active' => '1']);
        self::assertSame([], $ok['errors']);
        self::assertSame(12.5, $ok['data']['price']);
        self::assertSame('2026-11-30 23:59:00', $ok['data']['discount_to']);
        self::assertTrue($ok['data']['active']);

        $bad = OfferInput::validate(['price' => '-1', 'discount_price' => '20', 'quantity' => '1.5']);
        self::assertCount(2, $bad['errors']); // price + quantity (discount vs invalid price not compared)
        $bad = OfferInput::validate(['price' => '10', 'discount_price' => '12', 'quantity' => '1', 'discount_from' => '2026-12-01', 'discount_to' => '2026-11-01']);
        self::assertCount(2, $bad['errors']);
        self::assertCount(1, OfferInput::validate(['price' => '10', 'quantity' => '1', 'discount_price' => '5', 'discount_from' => '31/12/2026'])['errors']);

        $noDisc = OfferInput::validate(['price' => '10', 'quantity' => '0', 'discount_from' => '2026-01-01']);
        self::assertNull($noDisc['data']['discount_from']);
        self::assertFalse($noDisc['data']['active']);
    }

    public function testBulkStockParser(): void
    {
        $r = OfferInput::parseBulkStock("# sku;qty\nVENDOR-A-P1;12\n8000000000028, 3\n\nbad line here\nX 5\nVENDOR-A-P1\t20");
        self::assertSame(['VENDOR-A-P1' => 20, '8000000000028' => 3, 'X' => 5], $r['items']);
        self::assertCount(1, $r['errors']);
    }

    public function testShippingRuleInput(): void
    {
        $r = ShippingRuleInput::validate(['id_country' => '10', 'zipcode_pattern' => ' 20, 21 ,00100-00199', 'base_cost' => '6,90', 'free_threshold' => '60', 'reduced_threshold' => '25', 'reduced_cost' => '2.90', 'priority' => '5'], [10, 8]);
        self::assertSame([], $r['errors']);
        self::assertSame('20,21,00100-00199', $r['data']['zipcode_pattern']);
        self::assertSame(6.9, $r['data']['base_cost']);

        $r = ShippingRuleInput::validate(['id_country' => '99', 'zipcode_pattern' => '20;21', 'base_cost' => 'x', 'reduced_threshold' => '70', 'free_threshold' => '60'], [10]);
        self::assertCount(5, $r['errors']);

        $ex = ShippingRuleInput::validate(['id_country' => '10', 'zipcode_pattern' => '09', 'excluded' => '1', 'base_cost' => '5'], [10]);
        self::assertSame([], $ex['errors']);
        self::assertTrue($ex['data']['excluded']);
        self::assertSame(0.0, $ex['data']['base_cost']);

        self::assertFalse(ShippingRuleInput::normalizePattern('00199-00100'));
        self::assertNull(ShippingRuleInput::normalizePattern(' , '));
        self::assertSame('*', ShippingRuleInput::normalizePattern('*'));
    }

    public function testPeriodPresetsAndBuckets(): void
    {
        $today = new \DateTimeImmutable('2026-11-02');
        $p = Period::fromRequest('30d', today: $today);
        self::assertSame('2026-10-04', $p->from->format('Y-m-d'));
        self::assertSame(30, $p->days());
        self::assertSame('day', $p->granularity);
        self::assertCount(30, $p->buckets());
        self::assertSame('2026-09-04', $p->previous()->from->format('Y-m-d'));
        self::assertSame('2026-10-03', $p->previous()->to->format('Y-m-d'));

        $q = Period::fromRequest('90d', today: $today);
        self::assertSame('week', $q->granularity);
        self::assertSame('2026-W32', $q->buckets()[0]);
        $buckets = $q->buckets();
        self::assertSame('2026-W45', end($buckets));

        $y = Period::fromRequest('365d', today: $today);
        self::assertSame('month', $y->granularity);
        self::assertCount(13, $y->buckets());

        $c = Period::fromRequest('custom', '2026-10-31', '2026-10-01', 'month', $today);
        self::assertSame('2026-10-01', $c->from->format('Y-m-d'));
        self::assertSame(['2026-10'], $c->buckets());
        self::assertSame('30d', Period::fromRequest('custom', 'garbage', null, null, $today)->preset);
        self::assertSame('30d', Period::fromRequest('1000y', today: $today)->preset);
        self::assertSame('%x-W%v', Period::sqlBucketFormat('week'));
    }
}

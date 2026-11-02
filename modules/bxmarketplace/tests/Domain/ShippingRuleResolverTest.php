<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Tests\Domain;

use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Shipping\ShippingRule;
use BrainX\Marketplace\Domain\Shipping\ShippingRuleResolver;
use PHPUnit\Framework\TestCase;

final class ShippingRuleResolverTest extends TestCase
{
    public function testTiersAndCosts(): void
    {
        $r = new ShippingRule(3, 6.90, 60.0, 25.0, 2.90);
        self::assertSame(0.0, ShippingRuleResolver::cost($r, 0.0));
        self::assertSame(ShippingRuleResolver::TIER_NONE, ShippingRuleResolver::tier($r, 0.0));
        self::assertSame(6.90, ShippingRuleResolver::cost($r, 24.99));
        self::assertSame(2.90, ShippingRuleResolver::cost($r, 25.0));
        self::assertSame(ShippingRuleResolver::TIER_REDUCED, ShippingRuleResolver::tier($r, 59.99));
        self::assertSame(0.0, ShippingRuleResolver::cost($r, 60.0));
        self::assertSame(ShippingRuleResolver::TIER_FREE, ShippingRuleResolver::tier($r, 100.0));
        // floating point sums right at the threshold count as reached
        self::assertSame(0.0, ShippingRuleResolver::cost(new ShippingRule(1, 5.0, 30.0), 0.1 + 0.2 + 29.7));
    }

    public function testZipPatterns(): void
    {
        self::assertTrue(ShippingRuleResolver::zipMatches('09', '09124'));
        self::assertFalse(ShippingRuleResolver::zipMatches('09', '20121'));
        self::assertTrue(ShippingRuleResolver::zipMatches('20,21', '21100'));
        self::assertTrue(ShippingRuleResolver::zipMatches('00100-00199', '00118'));
        self::assertFalse(ShippingRuleResolver::zipMatches('00100-00199', '00200'));
        self::assertTrue(ShippingRuleResolver::zipMatches('070-089', '08020'));
        self::assertTrue(ShippingRuleResolver::zipMatches('*', '12345'));
        self::assertFalse(ShippingRuleResolver::zipMatches('09', ''));
        self::assertTrue(ShippingRuleResolver::zipMatches('sw1', 'SW1A 1AA'));
    }

    public function testResolvePriorityAndSpecificity(): void
    {
        $res = new ShippingRuleResolver();
        $catchAll = new ShippingRule(3, 6.90, 60.0, 25.0, 2.90, 0, null, false, 0, 1);
        $italy = new ShippingRule(3, 4.90, 50.0, null, null, 10, null, false, 0, 2);
        $sardinia = new ShippingRule(3, 0.0, null, null, null, 10, '09', true, 10, 3);
        $rules = [$catchAll, $italy, $sardinia];

        self::assertSame($italy, $res->resolve($rules, new Destination(10, '20121')));
        self::assertSame($catchAll, $res->resolve($rules, new Destination(8, '75001')));
        self::assertSame($sardinia, $res->resolve($rules, new Destination(10, '09124')));
        self::assertFalse($res->canDeliver($rules, new Destination(10, '09124')));
        self::assertNull($res->costFor($rules, new Destination(10, '09124'), 10.0));
        self::assertSame(4.90, $res->costFor($rules, new Destination(10, '20121'), 10.0));
    }

    public function testVendorWithoutRulesShipsFreeAndUnmatchedRulesMeanNoDelivery(): void
    {
        $res = new ShippingRuleResolver();
        self::assertTrue($res->canDeliver([], new Destination(10, '20121')));
        self::assertSame(0.0, $res->costFor([], new Destination(10, '20121'), 50.0));
        $onlyFrance = [new ShippingRule(1, 5.0, null, null, null, 8)];
        self::assertFalse($res->canDeliver($onlyFrance, new Destination(10, '20121')));
    }

    public function testFromArray(): void
    {
        $r = ShippingRule::fromArray(['id_shipping_rule' => '7', 'id_vendor' => '3', 'id_country' => '0', 'zipcode_pattern' => '', 'excluded' => '0', 'base_cost' => '6.900000', 'free_threshold' => '60.000000', 'reduced_threshold' => null, 'reduced_cost' => null, 'priority' => '0']);
        self::assertSame(7, $r->id);
        self::assertNull($r->zipcodePattern);
        self::assertNull($r->reducedThreshold);
        self::assertSame(60.0, $r->freeThreshold);
    }
}

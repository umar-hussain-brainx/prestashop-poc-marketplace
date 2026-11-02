<?php

declare(strict_types=1);

namespace BrainX\Csv\Tests\Unit;

use BrainX\Csv\Validation\OrderStatusRowValidator;
use PHPUnit\Framework\TestCase;

final class OrderStatusRowValidatorTest extends TestCase
{
    public function testValidAndAliases(): void
    {
        $v = new OrderStatusRowValidator();
        $r = $v->validate(['vendor_order_reference' => 'VO-1', 'status' => 'Spedito', 'tracking_number' => '1Z999', 'carrier_name' => 'UPS']);
        self::assertTrue($r->isValid());
        self::assertSame('shipped', $r->get('status'));
        self::assertSame('1Z999', $r->get('tracking_number'));
        $r = $v->validate(['vendor_order_reference' => 'VO-1', 'status' => '', 'tracking_number' => 'T1']);
        self::assertTrue($r->isValid());
        self::assertNull($r->get('status'));
        self::assertSame('cancelled', OrderStatusRowValidator::normalizeStatus('canceled'));
        self::assertFalse(OrderStatusRowValidator::normalizeStatus('lost'));
    }

    public function testErrorsAndWarnings(): void
    {
        $v = new OrderStatusRowValidator();
        self::assertFalse($v->validate(['vendor_order_reference' => '', 'status' => 'shipped'])->isValid());
        self::assertFalse($v->validate(['vendor_order_reference' => 'X', 'status' => 'lost'])->isValid());
        self::assertFalse($v->validate(['vendor_order_reference' => 'X', 'status' => ''])->isValid(), 'nothing to update');
        $w = $v->validate(['vendor_order_reference' => 'X', 'status' => 'shipped']);
        self::assertTrue($w->isValid());
        self::assertNotEmpty($w->warnings);
    }

    public function testTransitions(): void
    {
        self::assertTrue(OrderStatusRowValidator::canTransition('pending', 'accepted'));
        self::assertTrue(OrderStatusRowValidator::canTransition('accepted', 'shipped'));
        self::assertTrue(OrderStatusRowValidator::canTransition('shipped', 'delivered'));
        self::assertTrue(OrderStatusRowValidator::canTransition('shipped', 'shipped'));
        self::assertFalse(OrderStatusRowValidator::canTransition('delivered', 'pending'));
        self::assertFalse(OrderStatusRowValidator::canTransition('cancelled', 'shipped'));
        self::assertFalse(OrderStatusRowValidator::canTransition('shipped', 'cancelled'));
    }
}

<?php

declare(strict_types=1);

namespace BrainX\Csv\Tests\Unit;

use BrainX\Csv\Parser\CsvException;
use BrainX\Csv\Schema\OfferSchema;
use BrainX\Csv\Schema\OrderStatusSchema;
use BrainX\Csv\Schema\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testHeaderNormalisationAndAliases(): void
    {
        self::assertSame('price', Schema::normalizeHeader(' Price (tax excl) '));
        self::assertSame('vendor_sku', Schema::normalizeHeader("\xEF\xBB\xBFVendor-SKU"));
        self::assertSame('quantita', Schema::normalizeHeader('Quantità'));

        $m = (new OfferSchema())->mapHeader(['SKU', 'EAN13', 'MPN', 'Manufacturer', 'Name', 'Price (tax excl)', 'Qty', 'Foo', 'Stock']);
        self::assertSame([0 => 'vendor_sku', 1 => 'ean', 2 => 'reference', 3 => 'brand', 4 => 'title', 5 => 'price', 6 => 'quantity'], $m['map']);
        self::assertSame(['Foo'], $m['unknown']);
        self::assertSame(['Stock'], $m['duplicates']);
    }

    public function testMissingRequiredColumns(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('quantity');
        (new OfferSchema())->mapHeader(['vendor_sku', 'price']);
    }

    public function testCombine(): void
    {
        $map = [0 => 'vendor_order_reference', 2 => 'status'];
        self::assertSame(['vendor_order_reference' => 'ABC', 'status' => 'shipped'], Schema::combine($map, [' ABC ', 'x', 'shipped ']));
        self::assertSame(['vendor_order_reference' => 'ABC', 'status' => ''], Schema::combine($map, ['ABC']));
        $s = (new OrderStatusSchema())->mapHeader(['Order Reference', 'State', 'Tracking', 'Carrier']);
        self::assertSame(['vendor_order_reference', 'status', 'tracking_number', 'carrier_name'], array_values($s['map']));
    }

    public function testTemplateHeaderListsAllColumns(): void
    {
        $h = OfferSchema::templateHeader();
        foreach (['vendor_sku', 'ean', 'reference', 'brand', 'title', 'description', 'category', 'price', 'discount_price', 'discount_from', 'discount_to', 'quantity', 'images', 'condition', 'variant_attributes', 'active'] as $c) {
            self::assertContains($c, $h);
        }
    }
}

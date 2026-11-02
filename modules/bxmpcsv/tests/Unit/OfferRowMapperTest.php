<?php

declare(strict_types=1);

namespace BrainX\Csv\Tests\Unit;

use BrainX\Csv\Validation\OfferRowMapper;
use BrainX\Csv\Validation\OfferRowValidator;
use BrainX\Marketplace\Domain\Matching\InMemoryCatalog;
use BrainX\Marketplace\Domain\Matching\MasterProduct;
use BrainX\Marketplace\Domain\Matching\MatchingEngine;
use BrainX\Marketplace\Domain\Matching\MatchResult;
use PHPUnit\Framework\TestCase;

final class OfferRowMapperTest extends TestCase
{
    private function data(array $over = []): array
    {
        $r = (new OfferRowValidator(','))->validate($over + [
            'vendor_sku' => 'A-1', 'ean' => '8002000000013', 'reference' => 'MPN-1', 'brand' => 'Studio Design', 'title' => 'Mug red',
            'description' => '<p>A <b>nice</b> mug &amp; more</p>', 'category' => 'Home > Kitchen > Mugs', 'price' => '12,90',
            'discount_price' => '9,90', 'discount_from' => '2026-11-01', 'discount_to' => '2026-11-30', 'quantity' => '4',
            'images' => 'https://x.example/1.jpg', 'condition' => 'used', 'variant_attributes' => '', 'parent_reference' => '', 'active' => '0',
        ]);
        self::assertTrue($r->isValid(), implode('; ', $r->errors));

        return $r->data;
    }

    public function testToOffer(): void
    {
        $o = OfferRowMapper::toOffer($this->data(), 1, 20, 0);
        self::assertSame([
            'id_vendor' => 1, 'id_product' => 20, 'id_product_attribute' => 0, 'vendor_sku' => 'A-1', 'ean13' => '8002000000013',
            'price' => 12.9, 'discount_price' => 9.9, 'discount_from' => '2026-11-01 00:00:00', 'discount_to' => '2026-11-30 23:59:59',
            'quantity' => 4, 'condition' => 'used', 'active' => false,
        ], $o);
        $noDisc = OfferRowMapper::toOffer($this->data(['discount_price' => '', 'discount_from' => '', 'discount_to' => '']), 1, 20, 3);
        self::assertNull($noDisc['discount_price']);
        self::assertNull($noDisc['discount_from']);
        self::assertSame(3, $noDisc['id_product_attribute']);
    }

    public function testToProductDraft(): void
    {
        $d = OfferRowMapper::toProductDraft($this->data());
        self::assertSame('Mug red', $d['name']);
        self::assertSame('<p>A nice mug &amp; more</p>', $d['description_short']);
        self::assertSame('MPN-1', $d['reference']);
        self::assertSame('MPN-1', $d['mpn']);
        self::assertSame(12.9, $d['price']);
        self::assertSame('Studio Design', $d['brand']);

        $v = OfferRowMapper::toProductDraft($this->data(['variant_attributes' => 'Color:Red|Size:M', 'parent_reference' => 'TEE-1', 'reference' => 'TEE-1-RM']));
        self::assertSame('TEE-1', $v['reference'], 'product reference = variant group');
        self::assertSame('TEE-1-RM', $v['combination_reference']);
        self::assertSame(['Color' => 'Red', 'Size' => 'M'], $v['variant_attributes']);
    }

    public function testCategoryPath(): void
    {
        self::assertSame(['Kitchen', 'Mugs'], OfferRowMapper::categoryPath('Home > Kitchen > Mugs'));
        self::assertSame(['Clothes', 'Men'], OfferRowMapper::categoryPath('Clothes/Men'));
        self::assertSame(['Accessories'], OfferRowMapper::categoryPath(' Accessories '));
    }

    public function testVendorRowFeedsTheCoreMatchingEngine(): void
    {
        $catalog = new InMemoryCatalog([
            new MasterProduct(1, 0, '8002000000013', 'demo_1', null, 'Studio Design', 'Hummingbird printed t-shirt'),
            new MasterProduct(3, 0, '8002000000037', 'demo_6', null, 'Graphic Corner', 'The best is yet to come framed poster'),
        ]);
        $engine = new MatchingEngine($catalog);
        $matched = $engine->match(OfferRowMapper::toVendorRow($this->data()));
        self::assertSame(MatchResult::MATCHED, $matched->status);
        self::assertSame(1, $matched->idProduct);

        $conflict = $engine->match(OfferRowMapper::toVendorRow($this->data(['ean' => '8002000000037', 'brand' => 'Acme'])));
        self::assertSame(MatchResult::CONFLICT, $conflict->status);

        $new = $engine->match(OfferRowMapper::toVendorRow($this->data(['ean' => '8003000000010', 'reference' => 'ZZZ', 'title' => 'Completely different lamp'])));
        self::assertSame(MatchResult::NEW, $new->status);
    }
}

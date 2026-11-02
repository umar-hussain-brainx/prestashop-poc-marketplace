<?php

declare(strict_types=1);

namespace BrainX\Csv\Tests\Unit;

use BrainX\Csv\Validation\OfferRowValidator;
use PHPUnit\Framework\TestCase;

final class OfferRowValidatorTest extends TestCase
{
    private function row(array $over = []): array
    {
        return $over + [
            'vendor_sku' => 'A-100', 'ean' => '8002000000013', 'reference' => 'demo_1', 'brand' => 'Studio Design',
            'title' => 'Hummingbird printed t-shirt', 'description' => '<p>Nice</p>', 'category' => 'Home > Clothes',
            'price' => '1.234,50', 'discount_price' => '999,00', 'discount_from' => '2026-11-01', 'discount_to' => '30/11/2026',
            'quantity' => '12', 'images' => 'https://cdn.example.com/a.jpg|https://cdn.example.com/b.jpg', 'condition' => 'Nuovo',
            'variant_attributes' => 'Color:Red|Size:M', 'parent_reference' => '', 'active' => 'yes',
        ];
    }

    public function testValidRowIsNormalised(): void
    {
        $r = (new OfferRowValidator(','))->validate($this->row(), 7);
        self::assertTrue($r->isValid(), implode('; ', $r->errors));
        self::assertSame(7, $r->line);
        self::assertSame('8002000000013', $r->get('ean'));
        self::assertSame(1234.5, $r->get('price'));
        self::assertSame(999.0, $r->get('discount_price'));
        self::assertSame('2026-11-01 00:00:00', $r->get('discount_from'));
        self::assertSame('2026-11-30 23:59:59', $r->get('discount_to'));
        self::assertSame(12, $r->get('quantity'));
        self::assertSame('new', $r->get('condition'));
        self::assertSame(['Color' => 'Red', 'Size' => 'M'], $r->get('variant_attributes'));
        self::assertCount(2, $r->get('images'));
        self::assertTrue($r->get('active'));
        self::assertSame([], $r->warnings);
    }

    public function testMinimalRowDefaults(): void
    {
        $r = (new OfferRowValidator())->validate(['vendor_sku' => 'X', 'ean' => '', 'title' => 'Thing', 'price' => '5', 'quantity' => '0']);
        self::assertTrue($r->isValid(), implode('; ', $r->errors));
        self::assertNull($r->get('ean'));
        self::assertNull($r->get('discount_price'));
        self::assertSame('new', $r->get('condition'));
        self::assertTrue($r->get('active'));
        self::assertSame([], $r->get('variant_attributes'));
    }

    public function testEanVariants(): void
    {
        $v = new OfferRowValidator();
        self::assertSame('0012345678905', $v->validate($this->row(['ean' => '012345678905']))->get('ean'), 'UPC-A padded');
        self::assertSame('8002000000013', $v->validate($this->row(['ean' => '08002000000013']))->get('ean'), 'GTIN-14 with leading 0');
        self::assertSame('8002000000013', $v->validate($this->row(['ean' => '800-2000-000013']))->get('ean'));
        $bad = $v->validate($this->row(['ean' => '8000000000012']));
        self::assertTrue($bad->isValid());
        self::assertStringContainsString('check digit', implode(' ', $bad->warnings));
        $sci = $v->validate($this->row(['ean' => '8,00E+12']));
        self::assertStringContainsString('scientific notation', implode(' ', $sci->errors));
        self::assertFalse($v->validate($this->row(['ean' => '12345']))->isValid());
        self::assertFalse($v->validate($this->row(['ean' => '80020000000AB']))->isValid());
        $ean8 = $v->validate($this->row(['ean' => '96385074']));
        self::assertTrue($ean8->isValid());
        self::assertNull($ean8->get('ean'));
    }

    public function testErrorsAreCollectedPerColumn(): void
    {
        $r = (new OfferRowValidator(','))->validate($this->row([
            'vendor_sku' => '', 'price' => 'abc', 'quantity' => '1,5', 'discount_from' => '31/02/2026',
            'condition' => 'broken', 'active' => 'maybe', 'variant_attributes' => 'Red', 'reference' => 'a<b',
        ]));
        self::assertFalse($r->isValid());
        $cols = array_map(static fn ($e) => strstr($e, ':', true), $r->errors);
        foreach (['vendor_sku', 'reference', 'price', 'discount_from', 'quantity', 'condition', 'variant_attributes', 'active'] as $c) {
            self::assertContains($c, $cols, $c . ' expected in ' . implode(' / ', $r->errors));
        }
    }

    public function testBusinessRules(): void
    {
        $v = new OfferRowValidator(',');
        self::assertContains('discount_price: must be lower than price', $v->validate($this->row(['price' => '10', 'discount_price' => '12']))->errors);
        self::assertContains('discount_to: must be after discount_from', $v->validate($this->row(['discount_from' => '2026-12-10', 'discount_to' => '2026-12-01']))->errors);
        self::assertContains('price: must be greater than 0', $v->validate($this->row(['price' => '0']))->errors);
        self::assertContains('row: no product identifier (ean, reference or title required)', $v->validate($this->row(['ean' => '', 'reference' => '', 'title' => '']))->errors);
        $neg = $v->validate($this->row(['quantity' => '-3']));
        self::assertTrue($neg->isValid());
        self::assertSame(0, $neg->get('quantity'));
        $noDisc = $v->validate($this->row(['discount_price' => '']));
        self::assertNull($noDisc->get('discount_from'));
        self::assertNotEmpty($noDisc->warnings);
    }

    public function testTitleAndHtmlSanitising(): void
    {
        $r = (new OfferRowValidator())->validate($this->row([
            'title' => 'Mug <b>#1</b> ' . str_repeat('x', 200),
            'description' => '<p onclick="evil()">Hi</p><script>alert(1)</script><a href="javascript:x()">l</a>',
        ]));
        self::assertTrue($r->isValid());
        self::assertSame(128, mb_strlen($r->get('title')));
        self::assertStringNotContainsString('<', $r->get('title'));
        self::assertSame('<p>Hi</p><a href="#">l</a>', $r->get('description'));
        self::assertCount(2, $r->warnings);
    }

    public function testImagesBestEffort(): void
    {
        $r = (new OfferRowValidator())->validate($this->row(['images' => 'https://ok.example/a.jpg | ftp://x/y.jpg |not a url| http://ok.example/b%20c.png']));
        self::assertTrue($r->isValid());
        self::assertSame(['https://ok.example/a.jpg', 'http://ok.example/b%20c.png'], $r->get('images'));
        self::assertCount(2, $r->warnings);
    }

    public function testVariantParsing(): void
    {
        self::assertSame(['Color' => 'Red', 'Size' => 'M'], OfferRowValidator::parseVariantAttributes('Color: Red | Size=M'));
        self::assertSame(['Color' => 'Blue'], OfferRowValidator::parseVariantAttributes('Color:Blue'));
        self::assertSame(['Color' => 'Blue', 'Size' => 'L'], OfferRowValidator::parseVariantAttributes('Color:Blue,Size:L'));
        self::assertNull(OfferRowValidator::parseVariantAttributes('Color'));
        self::assertNull(OfferRowValidator::parseVariantAttributes(':Red'));
    }

    public function testBooleans(): void
    {
        foreach (['1', 'yes', 'TRUE', 'sì', 'y'] as $t) {
            self::assertTrue(OfferRowValidator::parseBool($t), $t);
        }
        foreach (['0', 'no', 'False', 'n', 'off'] as $f) {
            self::assertFalse(OfferRowValidator::parseBool($f), $f);
        }
        self::assertNull(OfferRowValidator::parseBool('maybe'));
    }
}

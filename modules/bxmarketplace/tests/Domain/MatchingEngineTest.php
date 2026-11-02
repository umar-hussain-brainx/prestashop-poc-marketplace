<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Tests\Domain;

use BrainX\Marketplace\Domain\Matching\InMemoryCatalog;
use BrainX\Marketplace\Domain\Matching\MasterProduct;
use BrainX\Marketplace\Domain\Matching\MatchingEngine;
use BrainX\Marketplace\Domain\Matching\MatchResult;
use BrainX\Marketplace\Domain\Matching\VendorRow;
use PHPUnit\Framework\TestCase;

final class MatchingEngineTest extends TestCase
{
    private function engine(bool $autoTitle = false): MatchingEngine
    {
        return new MatchingEngine(new InMemoryCatalog([
            new MasterProduct(1, 0, '8000000000011', 'P-001', null, 'Acme', 'Product 1'),
            new MasterProduct(2, 0, '8000000000028', 'P-002', null, 'Acme', 'Product 2'),
            new MasterProduct(3, 0, '4006381333931', 'STAB-88', 'MPN-88', 'Stabilo', 'Stabilo Boss Original Highlighter Yellow'),
            new MasterProduct(4, 11, '5901234123457', 'TS-RED-M', null, 'Hummingbird', 'Hummingbird printed t-shirt Red M'),
            new MasterProduct(5, 0, '9780201379624', 'DUP-1', null, 'Other', 'Duplicate A'),
            new MasterProduct(6, 0, '9780201379624', 'DUP-2', null, 'Other', 'Duplicate B'),
            new MasterProduct(7, 0, null, 'MUG-1', null, 'Acme', 'Mug The best is yet to come'),
            new MasterProduct(8, 0, null, 'MUG-1', null, 'Globex', 'Mug Globex'),
        ]), 0.6, $autoTitle);
    }

    public function testEanChecksum(): void
    {
        self::assertTrue(MatchingEngine::isValidEan13('4006381333931'));
        self::assertTrue(MatchingEngine::isValidEan13('8000000000019'));
        // the client demo EANs carry non-GS1 check digits
        self::assertFalse(MatchingEngine::isValidEan13('8000000000011'));
        self::assertFalse(MatchingEngine::isValidEan13('8000000000028'));
        self::assertSame(9, MatchingEngine::ean13CheckDigit('800000000001'));
        self::assertNull(MatchingEngine::normalizeEan('8000000000011'));
        self::assertSame('8000000000011', MatchingEngine::normalizeEan('8000000000011', true));
        self::assertSame('0012345678905', MatchingEngine::normalizeEan('012345678905')); // UPC-A
        self::assertSame('8000000000019', MatchingEngine::normalizeEan(' 800-0000-000019 '));
        self::assertNull(MatchingEngine::normalizeEan('123'));
    }

    public function testExactEanMatchIncludingVariant(): void
    {
        $r = $this->engine()->match(new VendorRow('8000000000028', null, 'ACME', 'whatever'));
        self::assertSame(MatchResult::MATCHED, $r->status);
        self::assertSame(2, $r->idProduct);
        self::assertSame('ean_exact_checksum_warning', $r->reason);
        self::assertSame('ean_exact', $this->engine()->match(new VendorRow('4006381333931'))->reason);

        $v = $this->engine()->match(new VendorRow('5901234123457'));
        self::assertSame(4, $v->idProduct);
        self::assertSame(11, $v->idProductAttribute);
    }

    public function testStrictChecksumModeIgnoresInvalidEan(): void
    {
        $strict = new MatchingEngine(new InMemoryCatalog([new MasterProduct(1, 0, '8000000000011', 'P-001', null, 'Acme', 'Product 1')]), 0.6, false, 0.95, true);
        $r = $strict->match(new VendorRow('8000000000011', 'P-001', 'Acme'));
        self::assertSame(MatchResult::MATCHED, $r->status);
        self::assertSame('reference', $r->method);
    }

    public function testEanBrandConflict(): void
    {
        $r = $this->engine()->match(new VendorRow('8000000000011', null, 'Globex', 'Product 1'));
        self::assertSame(MatchResult::CONFLICT, $r->status);
        self::assertTrue($r->needsReview());
        self::assertSame([1], $r->candidateIds());
    }

    public function testDuplicateEanIsAmbiguous(): void
    {
        $r = $this->engine()->match(new VendorRow('9780201379624'));
        self::assertSame(MatchResult::AMBIGUOUS, $r->status);
        self::assertSame([5, 6], $r->candidateIds());
    }

    public function testInvalidEanFallsBackToReferenceAndBrand(): void
    {
        $r = $this->engine()->match(new VendorRow('12345', 'stab 88', 'STABILO', 'Highlighter'));
        self::assertSame(MatchResult::MATCHED, $r->status);
        self::assertSame(3, $r->idProduct);
        self::assertSame('reference', $r->method);

        $mpn = $this->engine()->match(new VendorRow(null, 'MPN-88', null, ''));
        self::assertSame(3, $mpn->idProduct);
    }

    public function testReferenceDisambiguatedByBrandOrConflict(): void
    {
        self::assertSame(8, $this->engine()->match(new VendorRow(null, 'MUG-1', 'Globex'))->idProduct);
        self::assertSame(MatchResult::AMBIGUOUS, $this->engine()->match(new VendorRow(null, 'MUG-1'))->status);
        self::assertSame(MatchResult::CONFLICT, $this->engine()->match(new VendorRow(null, 'MUG-1', 'Initech'))->status);
    }

    public function testTitleSimilarityGoesToReviewNotAutoMatch(): void
    {
        $r = $this->engine()->match(new VendorRow(null, null, 'Stabilo', 'STABILO BOSS original highlighter - yellow'));
        self::assertSame(MatchResult::AMBIGUOUS, $r->status);
        self::assertSame('title_similarity_needs_review', $r->reason);
        self::assertSame(3, $r->candidates[0]->idProduct);
        self::assertGreaterThan(0.9, $r->score);

        $auto = $this->engine(true)->match(new VendorRow(null, null, 'Stabilo', 'Stabilo Boss Original Highlighter Yellow'));
        self::assertSame(MatchResult::MATCHED, $auto->status);
        self::assertSame(3, $auto->idProduct);
    }

    public function testUnknownProductIsNew(): void
    {
        $r = $this->engine()->match(new VendorRow('4012345678901', 'ZZZ-1', 'Nobody', 'Completely unrelated garden hose'));
        self::assertSame(MatchResult::NEW, $r->status);
        self::assertStringContainsString('ean_not_found', $r->reason);
        self::assertFalse($r->needsReview());
    }

    public function testVendorRowFromArray(): void
    {
        $row = VendorRow::fromArray(['ean' => '8000000000011', 'sku' => 'A-1', 'title' => 'P1', 'brand' => '']);
        self::assertSame('8000000000011', $row->ean13);
        self::assertSame('A-1', $row->vendorSku);
        self::assertNull($row->brand);
    }
}

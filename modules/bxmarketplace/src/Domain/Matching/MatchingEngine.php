<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Matching;

/**
 * Master catalog matching: vendor row → master product (PrestaShop product / combination).
 *
 * Cascade (first conclusive step wins):
 *  1. EAN13 exact (normalised: digits only, UPC-A padded to 13). The GS1 check digit is validated; by default an
 *     invalid check digit is still used as an exact identifier (reason suffixed "_checksum_warning") because real
 *     vendor feeds (and the client demo EANs 8000000000011 / 8000000000028) contain such codes; set
 *     $strictEanChecksum = true to ignore them instead.
 *       1 hit  → matched (or conflict when the vendor brand clearly differs from the master brand)
 *       >1 hit → ambiguous (duplicate EAN in master catalog)
 *  2. reference / MPN + brand (normalised alphanumerics, brand must agree when both known)
 *       1 hit → matched; >1 → ambiguous; reference hit with different brand → conflict
 *  3. normalised title similarity (token Jaccard blended with similar_text) — NEVER auto-matched by default:
 *       best score ≥ titleThreshold → ambiguous (needs human review, candidates listed)
 *       (set $autoMatchTitle = true to auto-accept a unique candidate scoring ≥ autoTitleThreshold with same brand)
 *  4. nothing → new (candidate for master product creation)
 */
final class MatchingEngine
{
    public function __construct(
        private readonly CatalogLookup $catalog,
        private readonly float $titleThreshold = 0.6,
        private readonly bool $autoMatchTitle = false,
        private readonly float $autoTitleThreshold = 0.95,
        private readonly bool $strictEanChecksum = false,
    ) {
    }

    public function match(VendorRow $row): MatchResult
    {
        $notes = [];

        // 1. EAN
        if ($row->ean13 !== null) {
            $ean = self::normalizeEan($row->ean13, !$this->strictEanChecksum);
            $checksumOk = $ean !== null && self::isValidEan13($ean);
            if ($ean === null) {
                $notes[] = 'invalid_ean';
            } else {
                $hits = $this->catalog->findByEan($ean);
                if (count($hits) === 1) {
                    $p = $hits[0];
                    if ($row->brand !== null && $p->brand !== null && !self::sameBrand($row->brand, $p->brand)) {
                        return new MatchResult(MatchResult::CONFLICT, null, 0, $hits, 'ean_brand_mismatch', 'ean');
                    }

                    return new MatchResult(MatchResult::MATCHED, $p->idProduct, $p->idProductAttribute, $hits, $checksumOk ? 'ean_exact' : 'ean_exact_checksum_warning', 'ean', 1.0);
                }
                if (count($hits) > 1) {
                    return new MatchResult(MatchResult::AMBIGUOUS, null, 0, $hits, 'duplicate_ean_in_catalog', 'ean');
                }
                $notes[] = 'ean_not_found';
            }
        }

        // 2. reference / MPN + brand
        if ($row->reference !== null) {
            $hits = $this->catalog->findByReference($row->reference);
            if ($hits !== []) {
                $sameBrand = array_values(array_filter($hits, fn (MasterProduct $p) => $row->brand === null || $p->brand === null || self::sameBrand($row->brand, $p->brand)));
                if (count($sameBrand) === 1) {
                    $p = $sameBrand[0];

                    return new MatchResult(MatchResult::MATCHED, $p->idProduct, $p->idProductAttribute, $sameBrand, 'reference_brand', 'reference', 1.0);
                }
                if (count($sameBrand) > 1) {
                    return new MatchResult(MatchResult::AMBIGUOUS, null, 0, $sameBrand, 'duplicate_reference', 'reference');
                }

                return new MatchResult(MatchResult::CONFLICT, null, 0, $hits, 'reference_brand_mismatch', 'reference');
            }
            $notes[] = 'reference_not_found';
        }

        // 3. title similarity
        if (trim($row->title) !== '') {
            $scored = [];
            foreach ($this->catalog->searchByTitle($row->title, $row->brand) as $p) {
                if ($row->brand !== null && $p->brand !== null && !self::sameBrand($row->brand, $p->brand)) {
                    continue;
                }
                $s = self::titleSimilarity($row->title, $p->name);
                if ($s >= $this->titleThreshold) {
                    $scored[] = [$s, $p];
                }
            }
            usort($scored, static fn ($a, $b) => $b[0] <=> $a[0]);
            if ($scored !== []) {
                [$top, $p] = $scored[0];
                $candidates = array_map(static fn ($e) => $e[1], array_slice($scored, 0, 5));
                $uniqueTop = count($scored) === 1 || $scored[1][0] < $top - 0.05;
                if ($this->autoMatchTitle && $uniqueTop && $top >= $this->autoTitleThreshold && $row->brand !== null && $p->brand !== null) {
                    return new MatchResult(MatchResult::MATCHED, $p->idProduct, $p->idProductAttribute, $candidates, 'title_similarity', 'title', $top);
                }

                return new MatchResult(MatchResult::AMBIGUOUS, null, 0, $candidates, 'title_similarity_needs_review', 'title', $top);
            }
        }

        return new MatchResult(MatchResult::NEW, null, 0, [], $notes === [] ? 'no_identifier_match' : implode(',', $notes));
    }

    /**
     * Digits-only EAN-13 (UPC-A 12 digits is zero padded), or null. With $allowBadChecksum the GS1 check digit is not
     * enforced (length still is).
     */
    public static function normalizeEan(string $ean, bool $allowBadChecksum = false): ?string
    {
        $d = preg_replace('/\D+/', '', $ean) ?? '';
        if (strlen($d) === 12) {
            $d = '0' . $d;
        }
        if (strlen($d) !== 13 || (!$allowBadChecksum && !self::isValidEan13($d))) {
            return null;
        }

        return $d;
    }

    public static function isValidEan13(string $ean): bool
    {
        if (!preg_match('/^\d{13}$/', $ean)) {
            return false;
        }

        return self::ean13CheckDigit(substr($ean, 0, 12)) === (int) $ean[12];
    }

    /** Check digit for the first 12 digits of an EAN-13. */
    public static function ean13CheckDigit(string $first12): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $first12[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function normalizeReference(string $ref): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]+/', '', $ref) ?? '');
    }

    public static function sameBrand(string $a, string $b): bool
    {
        return self::normalizeReference(self::ascii($a)) === self::normalizeReference(self::ascii($b));
    }

    /** Normalised token set (lowercase ascii alphanumerics, stop-words and 1-char tokens removed). */
    public static function tokens(string $s): array
    {
        $s = strtolower(self::ascii($s));
        $parts = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['the', 'a', 'an', 'and', 'of', 'for', 'with', 'di', 'da', 'il', 'la', 'le', 'e', 'con', 'per'];

        return array_values(array_unique(array_filter($parts, static fn ($t) => strlen($t) > 1 && !in_array($t, $stop, true))));
    }

    /** 0..1 similarity: 0.6·Jaccard(tokens) + 0.4·similar_text(sorted tokens). */
    public static function titleSimilarity(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        $jaccard = $union > 0 ? $inter / $union : 0.0;
        sort($ta);
        sort($tb);
        similar_text(implode(' ', $ta), implode(' ', $tb), $pct);

        return 0.6 * $jaccard + 0.4 * ($pct / 100);
    }

    private static function ascii(string $s): string
    {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;

        return $t === false ? $s : $t;
    }
}

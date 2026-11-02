<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Matching;

/** Master catalog access used by the MatchingEngine (DB implementation in Infrastructure, in-memory for tests). */
interface CatalogLookup
{
    /** @return MasterProduct[] products/combinations whose EAN13 equals $ean13 */
    public function findByEan(string $ean13): array;

    /** @return MasterProduct[] products/combinations whose reference or MPN equals $reference (case-insensitive) */
    public function findByReference(string $reference): array;

    /** @return MasterProduct[] title search candidates (may be fuzzy / over-inclusive; engine scores them) */
    public function searchByTitle(string $title, ?string $brand, int $limit = 20): array;
}

<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Matching;

/** Simple array-backed catalog (tests, batch matching with a pre-loaded catalog). */
final class InMemoryCatalog implements CatalogLookup
{
    /** @param MasterProduct[] $products */
    public function __construct(private readonly array $products)
    {
    }

    public function findByEan(string $ean13): array
    {
        $ean13 = MatchingEngine::normalizeEan($ean13, true) ?? $ean13;

        return array_values(array_filter($this->products, static fn (MasterProduct $p) => $p->ean13 !== null && (MatchingEngine::normalizeEan($p->ean13, true) ?? $p->ean13) === $ean13));
    }

    public function findByReference(string $reference): array
    {
        $r = MatchingEngine::normalizeReference($reference);

        return array_values(array_filter($this->products, static fn (MasterProduct $p) => ($p->reference !== null && MatchingEngine::normalizeReference($p->reference) === $r)
            || ($p->mpn !== null && MatchingEngine::normalizeReference($p->mpn) === $r)));
    }

    public function searchByTitle(string $title, ?string $brand, int $limit = 20): array
    {
        return array_slice($this->products, 0, max($limit, count($this->products)));
    }
}

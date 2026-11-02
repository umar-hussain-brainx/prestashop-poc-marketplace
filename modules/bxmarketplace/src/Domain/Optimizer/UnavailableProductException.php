<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

/** Thrown when at least one basket line cannot be fully supplied by vendors delivering to the destination. */
final class UnavailableProductException extends \RuntimeException
{
    /** @param array<int, array{id_product:int,id_product_attribute:int,requested:int,available:int}> $lines */
    public function __construct(public readonly array $lines)
    {
        $parts = array_map(
            static fn (array $l): string => sprintf('product %d/%d (requested %d, available %d)', $l['id_product'], $l['id_product_attribute'], $l['requested'], $l['available']),
            $lines
        );
        parent::__construct('Not enough vendor stock delivering to the destination for: ' . implode('; ', $parts));
    }
}

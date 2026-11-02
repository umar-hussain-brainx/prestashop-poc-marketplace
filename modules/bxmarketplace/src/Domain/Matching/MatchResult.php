<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Matching;

/** Outcome of matching one vendor row against the master catalog. */
final class MatchResult
{
    public const MATCHED = 'matched';
    public const AMBIGUOUS = 'ambiguous';
    public const NEW = 'new';
    public const CONFLICT = 'conflict';

    /** @param MasterProduct[] $candidates */
    public function __construct(
        public readonly string $status,
        public readonly ?int $idProduct,
        public readonly int $idProductAttribute,
        public readonly array $candidates,
        public readonly string $reason,
        public readonly ?string $method = null,
        public readonly float $score = 0.0,
    ) {
    }

    public function isMatched(): bool
    {
        return $this->status === self::MATCHED;
    }

    /** Whether the row must go to the exception queue (bxmp_match_exception). */
    public function needsReview(): bool
    {
        return $this->status === self::AMBIGUOUS || $this->status === self::CONFLICT;
    }

    /** @return int[] */
    public function candidateIds(): array
    {
        return array_values(array_unique(array_map(static fn (MasterProduct $p) => $p->idProduct, $this->candidates)));
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'id_product' => $this->idProduct,
            'id_product_attribute' => $this->idProductAttribute,
            'candidates' => array_map(static fn (MasterProduct $p) => $p->toArray(), $this->candidates),
            'reason' => $this->reason,
            'method' => $this->method,
            'score' => round($this->score, 4),
        ];
    }
}

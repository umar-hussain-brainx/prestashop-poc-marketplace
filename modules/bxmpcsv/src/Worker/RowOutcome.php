<?php

declare(strict_types=1);

namespace BrainX\Csv\Worker;

/** Result of processing one valid row. */
final class RowOutcome
{
    public const UPDATED = 'updated';         // offer written on an existing master product / combination
    public const CREATED = 'created';         // master product created + offer
    public const COMBINATION = 'combinations'; // combination added to an existing (parent) product + offer
    public const EXCEPTION = 'exceptions';    // ambiguous / conflict / no auto-create → bxmp_match_exception
    public const SKIPPED = 'skipped';         // e.g. previously rejected exception
    public const FAILED = 'failed';           // unexpected error while writing

    /** @param string[] $warnings */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly int $idProduct = 0,
        public readonly int $idProductAttribute = 0,
        public readonly int $idOffer = 0,
        public readonly int $idException = 0,
        public readonly array $warnings = [],
    ) {
    }

    public function isOk(): bool
    {
        return in_array($this->status, [self::UPDATED, self::CREATED, self::COMBINATION], true);
    }
}

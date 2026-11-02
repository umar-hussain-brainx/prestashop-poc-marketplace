<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Commission;

/** Vendor commercial conditions: percent of line value + fixed amount per order line (EUR, tax excl.). */
final class CommissionTerms
{
    public function __construct(
        public readonly float $ratePercent,
        public readonly float $fixedPerLine = 0.0,
        public readonly bool $applyOnShipping = false,
    ) {
        if ($ratePercent < 0 || $ratePercent > 100) {
            throw new \InvalidArgumentException('Commission rate must be within 0..100 %');
        }
        if ($fixedPerLine < 0) {
            throw new \InvalidArgumentException('Fixed commission cannot be negative');
        }
    }

    /** From a bxmp_vendor row. */
    public static function fromVendorRow(array $row, bool $applyOnShipping = false): self
    {
        return new self((float) ($row['commission_rate'] ?? 0), (float) ($row['commission_fixed'] ?? 0), $applyOnShipping);
    }
}

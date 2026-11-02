<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Matching;

/** Identification part of a vendor catalog row (CSV / API). */
final class VendorRow
{
    public function __construct(
        public readonly ?string $ean13 = null,
        public readonly ?string $reference = null,
        public readonly ?string $brand = null,
        public readonly string $title = '',
        public readonly ?string $vendorSku = null,
    ) {
    }

    public static function fromArray(array $row): self
    {
        $s = static fn ($v): ?string => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);

        return new self($s($row['ean13'] ?? $row['ean'] ?? null), $s($row['reference'] ?? $row['mpn'] ?? null), $s($row['brand'] ?? null), (string) ($row['title'] ?? $row['name'] ?? ''), $s($row['vendor_sku'] ?? $row['sku'] ?? null));
    }
}

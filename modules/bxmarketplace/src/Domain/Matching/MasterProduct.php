<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Matching;

/** Master catalog entry = a native PrestaShop product or combination. */
final class MasterProduct
{
    public function __construct(
        public readonly int $idProduct,
        public readonly int $idProductAttribute = 0,
        public readonly ?string $ean13 = null,
        public readonly ?string $reference = null,
        public readonly ?string $mpn = null,
        public readonly ?string $brand = null,
        public readonly string $name = '',
    ) {
    }

    public static function fromArray(array $row): self
    {
        $s = static fn ($v): ?string => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);

        return new self((int) $row['id_product'], (int) ($row['id_product_attribute'] ?? 0), $s($row['ean13'] ?? null), $s($row['reference'] ?? null), $s($row['mpn'] ?? null), $s($row['brand'] ?? null), (string) ($row['name'] ?? ''));
    }

    public function toArray(): array
    {
        return ['id_product' => $this->idProduct, 'id_product_attribute' => $this->idProductAttribute, 'ean13' => $this->ean13, 'reference' => $this->reference, 'mpn' => $this->mpn, 'brand' => $this->brand, 'name' => $this->name];
    }
}

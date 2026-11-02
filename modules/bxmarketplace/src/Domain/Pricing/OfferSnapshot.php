<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Pricing;

/** Raw vendor offer data (mirror of a bxmp_offer row) used by the PriceComparator. Prices tax excluded. */
final class OfferSnapshot
{
    public function __construct(
        public readonly int $idOffer,
        public readonly int $idVendor,
        public readonly int $idProduct,
        public readonly int $idProductAttribute,
        public readonly float $price,
        public readonly int $quantity,
        public readonly ?float $discountPrice = null,
        public readonly ?\DateTimeImmutable $discountFrom = null,
        public readonly ?\DateTimeImmutable $discountTo = null,
        public readonly bool $active = true,
        public readonly string $condition = 'new',
        public readonly ?string $vendorName = null,
        public readonly ?string $vendorSku = null,
    ) {
    }

    /** From a DB row (bxmp_offer columns, optional vendor_name / vendor_active). */
    public static function fromArray(array $row): self
    {
        $date = static function ($v): ?\DateTimeImmutable {
            if ($v === null || $v === '' || str_starts_with((string) $v, '0000-00-00')) {
                return null;
            }

            return new \DateTimeImmutable((string) $v);
        };
        $discount = $row['discount_price'] ?? null;

        return new self(
            idOffer: (int) ($row['id_offer'] ?? 0),
            idVendor: (int) $row['id_vendor'],
            idProduct: (int) $row['id_product'],
            idProductAttribute: (int) ($row['id_product_attribute'] ?? 0),
            price: (float) $row['price'],
            quantity: (int) ($row['quantity'] ?? 0),
            discountPrice: ($discount === null || $discount === '') ? null : (float) $discount,
            discountFrom: $date($row['discount_from'] ?? null),
            discountTo: $date($row['discount_to'] ?? null),
            active: (bool) ($row['active'] ?? true) && (bool) ($row['vendor_active'] ?? true),
            condition: (string) ($row['condition'] ?? 'new'),
            vendorName: isset($row['vendor_name']) ? (string) $row['vendor_name'] : null,
            vendorSku: isset($row['vendor_sku']) ? (string) $row['vendor_sku'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

/**
 * A purchasable vendor offer as seen by the optimizer: the unit price is the EFFECTIVE price (discount window already
 * applied, tax excluded) and availableQuantity is the vendor stock.
 */
final class OfferCandidate
{
    public function __construct(
        public readonly int $idOffer,
        public readonly int $idVendor,
        public readonly int $idProduct,
        public readonly int $idProductAttribute,
        public readonly float $unitPrice,
        public readonly int $availableQuantity,
        public readonly ?string $vendorSku = null,
    ) {
        if ($unitPrice < 0) {
            throw new \InvalidArgumentException('Offer price cannot be negative');
        }
    }

    public function key(): string
    {
        return $this->idProduct . '-' . $this->idProductAttribute;
    }
}

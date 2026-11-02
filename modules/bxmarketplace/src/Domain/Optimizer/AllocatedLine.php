<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

/** Units of one product/variant bought from one offer. */
final class AllocatedLine
{
    public function __construct(
        public readonly int $idProduct,
        public readonly int $idProductAttribute,
        public readonly int $idOffer,
        public readonly int $idVendor,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly ?string $vendorSku = null,
    ) {
    }

    public function total(): float
    {
        return $this->unitPrice * $this->quantity;
    }

    public function toArray(): array
    {
        return [
            'id_product' => $this->idProduct,
            'id_product_attribute' => $this->idProductAttribute,
            'id_offer' => $this->idOffer,
            'vendor_sku' => $this->vendorSku,
            'quantity' => $this->quantity,
            'unit_price' => round($this->unitPrice, 6),
            'line_total' => round($this->total(), 2),
        ];
    }
}

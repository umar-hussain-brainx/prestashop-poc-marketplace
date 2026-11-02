<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

/** One requested master product/variant and quantity. Identity = (idProduct, idProductAttribute). */
final class BasketLine
{
    public function __construct(
        public readonly int $idProduct,
        public readonly int $idProductAttribute,
        public readonly int $quantity,
    ) {
        if ($idProduct <= 0) {
            throw new \InvalidArgumentException('BasketLine requires a positive id_product');
        }
        if ($idProductAttribute < 0) {
            throw new \InvalidArgumentException('id_product_attribute cannot be negative');
        }
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('BasketLine quantity must be > 0');
        }
    }

    public function key(): string
    {
        return $this->idProduct . '-' . $this->idProductAttribute;
    }

    public function toArray(): array
    {
        return ['id_product' => $this->idProduct, 'id_product_attribute' => $this->idProductAttribute, 'quantity' => $this->quantity];
    }
}

<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

/** One vendor sub-order of the optimal allocation. */
final class VendorAllocation
{
    /** @param AllocatedLine[] $lines */
    public function __construct(
        public readonly int $idVendor,
        public readonly array $lines,
        public readonly float $subtotal,
        public readonly float $shippingCost,
        public readonly string $shippingTier,
        public readonly ?string $vendorName = null,
    ) {
    }

    public function total(): float
    {
        return $this->subtotal + $this->shippingCost;
    }

    public function toArray(): array
    {
        return [
            'id_vendor' => $this->idVendor,
            'vendor_name' => $this->vendorName,
            'subtotal' => round($this->subtotal, 2),
            'shipping_cost' => round($this->shippingCost, 2),
            'shipping_tier' => $this->shippingTier,
            'total' => round($this->total(), 2),
            'lines' => array_map(static fn (AllocatedLine $l) => $l->toArray(), $this->lines),
        ];
    }
}

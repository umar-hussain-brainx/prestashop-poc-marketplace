<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Order;

/** Planned vendor sub-order (before persistence as bxmp_vendor_order + lines). Amounts tax excluded. */
final class VendorOrderPlan
{
    /**
     * @param array<int, array{id_offer:int,id_product:int,id_product_attribute:int,product_name:string,vendor_sku:?string,quantity:int,unit_price:float,commission_amount:float}> $lines
     */
    public function __construct(
        public readonly int $idVendor,
        public readonly string $reference,
        public readonly array $lines,
        public readonly float $totalProducts,
        public readonly float $totalShipping,
        public readonly float $commission,
        public readonly ?string $vendorName = null,
        public readonly ?string $walletId = null,
    ) {
    }

    public function totalPaid(): float
    {
        return round($this->totalProducts + $this->totalShipping, 2);
    }

    public function vendorPayout(): float
    {
        return round($this->totalPaid() - $this->commission, 2);
    }
}

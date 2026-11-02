<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Commission;

/** Commission breakdown of one vendor sub-order. */
final class OrderCommission
{
    /** @param float[] $lineCommissions same keys as the input lines */
    public function __construct(
        public readonly array $lineCommissions,
        public readonly float $productsTotal,
        public readonly float $shippingTotal,
        public readonly float $commission,
    ) {
    }

    public function totalPaid(): float
    {
        return round($this->productsTotal + $this->shippingTotal, 2);
    }

    public function vendorPayout(): float
    {
        return round($this->totalPaid() - $this->commission, 2);
    }

    public function toArray(): array
    {
        return [
            'line_commissions' => $this->lineCommissions,
            'total_products' => $this->productsTotal,
            'total_shipping' => $this->shippingTotal,
            'total_paid' => $this->totalPaid(),
            'commission_amount' => $this->commission,
            'vendor_payout' => $this->vendorPayout(),
        ];
    }
}

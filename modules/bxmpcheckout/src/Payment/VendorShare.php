<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment;

/** Part of one customer payment owed to one vendor sub-order (amounts tax excluded, EUR). */
final class VendorShare
{
    public function __construct(
        public readonly int $idVendor,
        public readonly int $idVendorOrder,
        public readonly string $vendorOrderReference,
        /** Mangopay wallet id of the vendor (bxmp_vendor.payout_account) */
        public readonly ?string $walletId,
        /** products + shipping of the sub-order */
        public readonly float $gross,
        /** platform commission (Mangopay "Fees" of the transfer) */
        public readonly float $commission,
        public readonly ?string $vendorName = null,
    ) {
        if ($gross < 0 || $commission < 0 || $commission > $gross + 0.000001) {
            throw new \InvalidArgumentException(sprintf('Invalid vendor share for vendor #%d (gross %.2f, commission %.2f)', $idVendor, $gross, $commission));
        }
    }

    public function payout(): float
    {
        return round($this->gross - $this->commission, 2);
    }
}

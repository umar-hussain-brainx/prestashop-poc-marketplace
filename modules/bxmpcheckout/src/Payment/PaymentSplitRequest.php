<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment;

/** One customer payment (one PrestaShop parent order) to be allocated across vendors. */
final class PaymentSplitRequest
{
    /** @param VendorShare[] $shares */
    public function __construct(
        public readonly int $idOrder,
        public readonly string $orderReference,
        public readonly int $idCustomer,
        public readonly string $currencyIso,
        /** what the customer actually pays (PS order total_paid, tax incl.) */
        public readonly float $amountPaid,
        /** PS payment module name (ps_wirepayment, ps_checkpayment, ...) */
        public readonly string $paymentModule,
        public readonly array $shares,
    ) {
        foreach ($shares as $s) {
            if (!$s instanceof VendorShare) {
                throw new \InvalidArgumentException('PaymentSplitRequest expects VendorShare[]');
            }
        }
    }

    public function sharesTotal(): float
    {
        return round(array_sum(array_map(static fn (VendorShare $s) => $s->gross, $this->shares)), 2);
    }

    public function commissionTotal(): float
    {
        return round(array_sum(array_map(static fn (VendorShare $s) => $s->commission, $this->shares)), 2);
    }
}

<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment;

/**
 * Allocates one customer payment across the vendors of a split order (escrow → vendor wallets, commission kept by
 * the platform). Implementations: MangopayPaymentSplitter (sandbox simulation / live transport), NullPaymentSplitter.
 */
interface PaymentSplitter
{
    public function name(): string;

    public function split(PaymentSplitRequest $request): PaymentSplitResult;
}

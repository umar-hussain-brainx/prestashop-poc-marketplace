<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment;

/** No payment provider: funds stay with the platform, vendors are paid through settlements (bxmpadmin). */
final class NullPaymentSplitter implements PaymentSplitter
{
    public function name(): string
    {
        return 'null';
    }

    public function split(PaymentSplitRequest $request): PaymentSplitResult
    {
        return new PaymentSplitResult('null', PaymentSplitResult::STATUS_SKIPPED, null, [], [], 'No payment splitter configured: vendors are paid via settlements.');
    }
}

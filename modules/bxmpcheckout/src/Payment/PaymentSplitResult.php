<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment;

/** Outcome of a payment split: references to store on bxmp_vendor_order.payment_transfer_ref. */
final class PaymentSplitResult
{
    public const STATUS_SIMULATED = 'simulated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<int, string> $transferRefs idVendor => transfer reference
     * @param array<string, mixed> $payloads    request payloads sent / to send (for audit)
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $payInRef,
        public readonly array $transferRefs,
        public readonly array $payloads = [],
        public readonly string $message = '',
    ) {
    }

    /** Compact reference stored on the vendor sub-order (≤ 128 chars). */
    public function referenceFor(int $idVendor): ?string
    {
        if (!isset($this->transferRefs[$idVendor]) && $this->payInRef === null) {
            return null;
        }
        $ref = $this->provider . ':' . ($this->transferRefs[$idVendor] ?? '-') . ($this->payInRef ? '|' . $this->payInRef : '');

        return substr($ref, 0, 128);
    }

    public function isOk(): bool
    {
        return $this->status !== self::STATUS_FAILED;
    }
}

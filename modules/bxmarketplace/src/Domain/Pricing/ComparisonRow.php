<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Pricing;

/** One ranked offer in a price comparison. */
final class ComparisonRow
{
    public function __construct(
        public readonly OfferSnapshot $offer,
        public readonly float $effectivePrice,
        public readonly bool $onDiscount,
        public readonly bool $available,
        /** null = destination unknown */
        public readonly ?bool $deliverable,
        /** shipping for buying only this item from this vendor (null if unknown / not deliverable) */
        public readonly ?float $shippingCost,
        /** effectivePrice·qty + shippingCost (or effectivePrice·qty when shipping unknown) */
        public readonly float $landedTotal,
        public readonly ?int $rank,
        public readonly bool $best,
        public readonly ?string $ineligibleReason = null,
    ) {
    }

    public function isEligible(): bool
    {
        return $this->available && $this->deliverable !== false;
    }

    public function toArray(): array
    {
        return [
            'id_offer' => $this->offer->idOffer,
            'id_vendor' => $this->offer->idVendor,
            'vendor_name' => $this->offer->vendorName,
            'id_product' => $this->offer->idProduct,
            'id_product_attribute' => $this->offer->idProductAttribute,
            'condition' => $this->offer->condition,
            'price' => round($this->offer->price, 6),
            'effective_price' => round($this->effectivePrice, 6),
            'on_discount' => $this->onDiscount,
            'quantity' => $this->offer->quantity,
            'available' => $this->available,
            'deliverable' => $this->deliverable,
            'shipping_cost' => $this->shippingCost === null ? null : round($this->shippingCost, 2),
            'landed_total' => round($this->landedTotal, 2),
            'rank' => $this->rank,
            'best' => $this->best,
            'ineligible_reason' => $this->ineligibleReason,
        ];
    }
}

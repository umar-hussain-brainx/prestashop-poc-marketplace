<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

use BrainX\Marketplace\Domain\Shipping\ShippingRule;

/**
 * A vendor's shipping rules (all destinations). The optimizer resolves the applicable rule per destination with
 * ShippingRuleResolver. A vendor without a policy (or with an empty rule list) ships for free everywhere.
 */
final class ShippingPolicy
{
    /** @param ShippingRule[] $rules */
    public function __construct(
        public readonly int $idVendor,
        public readonly array $rules = [],
        public readonly ?string $vendorName = null,
    ) {
        foreach ($rules as $rule) {
            if (!$rule instanceof ShippingRule) {
                throw new \InvalidArgumentException('ShippingPolicy expects ShippingRule[]');
            }
        }
    }

    /** Convenience: one catch-all rule (all countries). */
    public static function flat(
        int $idVendor,
        float $baseCost,
        ?float $freeThreshold = null,
        ?float $reducedThreshold = null,
        ?float $reducedCost = null,
        ?string $vendorName = null,
    ): self {
        return new self($idVendor, [new ShippingRule($idVendor, $baseCost, $freeThreshold, $reducedThreshold, $reducedCost)], $vendorName);
    }

    public function label(): string
    {
        return $this->vendorName ?? ('Vendor #' . $this->idVendor);
    }
}

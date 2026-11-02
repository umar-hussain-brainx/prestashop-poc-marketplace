<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Shipping;

/**
 * Immutable vendor shipping rule (mirror of a bxmp_shipping_rule row). Money is tax excluded.
 */
final class ShippingRule
{
    public function __construct(
        public readonly int $vendorId,
        public readonly float $baseCost,
        public readonly ?float $freeThreshold = null,
        public readonly ?float $reducedThreshold = null,
        public readonly ?float $reducedCost = null,
        public readonly int $countryId = 0,
        public readonly ?string $zipcodePattern = null,
        public readonly bool $excluded = false,
        public readonly int $priority = 0,
        public readonly int $id = 0,
    ) {
        if ($baseCost < 0 || ($reducedCost !== null && $reducedCost < 0)) {
            throw new \InvalidArgumentException('Shipping costs cannot be negative');
        }
    }

    /** Builds from a DB row / API array using the bxmp_shipping_rule column names. */
    public static function fromArray(array $row): self
    {
        $nullableFloat = static fn ($v): ?float => ($v === null || $v === '') ? null : (float) $v;
        $pattern = isset($row['zipcode_pattern']) && trim((string) $row['zipcode_pattern']) !== '' ? trim((string) $row['zipcode_pattern']) : null;

        return new self(
            vendorId: (int) ($row['id_vendor'] ?? 0),
            baseCost: (float) ($row['base_cost'] ?? 0),
            freeThreshold: $nullableFloat($row['free_threshold'] ?? null),
            reducedThreshold: $nullableFloat($row['reduced_threshold'] ?? null),
            reducedCost: $nullableFloat($row['reduced_cost'] ?? null),
            countryId: (int) ($row['id_country'] ?? 0),
            zipcodePattern: $pattern,
            excluded: (bool) ($row['excluded'] ?? false),
            priority: (int) ($row['priority'] ?? 0),
            id: (int) ($row['id_shipping_rule'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'id_shipping_rule' => $this->id,
            'id_vendor' => $this->vendorId,
            'id_country' => $this->countryId,
            'zipcode_pattern' => $this->zipcodePattern,
            'excluded' => $this->excluded,
            'base_cost' => $this->baseCost,
            'free_threshold' => $this->freeThreshold,
            'reduced_threshold' => $this->reducedThreshold,
            'reduced_cost' => $this->reducedCost,
            'priority' => $this->priority,
        ];
    }
}

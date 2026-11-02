<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

/**
 * Optimizer output: per-vendor breakdown, totals, strategy metadata and a human readable explanation of the
 * alternatives that were evaluated.
 */
final class AllocationResult
{
    public const MODE_EXACT = 'exact';
    public const MODE_HEURISTIC = 'heuristic';

    /**
     * @param VendorAllocation[] $vendors
     * @param array<int, array{label:string,total:float|null,feasible:bool,vendors:int[],note?:string}> $alternatives
     * @param string[] $explanation
     * @param array<int, array{id_offer:int,id_vendor:int,reason:string}> $excludedOffers
     * @param array<int, array{id_product:int,id_product_attribute:int,requested:int,available:int}> $unavailable
     */
    public function __construct(
        public readonly array $vendors,
        public readonly float $itemsTotal,
        public readonly float $shippingTotal,
        public readonly string $mode,
        public readonly bool $provenOptimal,
        public readonly int $nodesExplored,
        public readonly float $durationMs,
        public readonly array $alternatives = [],
        public readonly array $explanation = [],
        public readonly array $excludedOffers = [],
        public readonly array $unavailable = [],
    ) {
    }

    public function total(): float
    {
        return $this->itemsTotal + $this->shippingTotal;
    }

    public function isComplete(): bool
    {
        return $this->unavailable === [];
    }

    public function vendorCount(): int
    {
        return count($this->vendors);
    }

    public function getVendor(int $idVendor): ?VendorAllocation
    {
        foreach ($this->vendors as $v) {
            if ($v->idVendor === $idVendor) {
                return $v;
            }
        }

        return null;
    }

    /** @return AllocatedLine[] flat list of all allocated lines */
    public function lines(): array
    {
        $out = [];
        foreach ($this->vendors as $v) {
            foreach ($v->lines as $l) {
                $out[] = $l;
            }
        }

        return $out;
    }

    /**
     * Blended (quantity weighted) unit price per product/variant — what a cart-scoped SpecificPrice should use.
     *
     * @return array<string, float> key "idProduct-idProductAttribute" => unit price
     */
    public function blendedUnitPrices(): array
    {
        $sum = [];
        $qty = [];
        foreach ($this->lines() as $l) {
            $k = $l->idProduct . '-' . $l->idProductAttribute;
            $sum[$k] = ($sum[$k] ?? 0) + $l->total();
            $qty[$k] = ($qty[$k] ?? 0) + $l->quantity;
        }
        $out = [];
        foreach ($sum as $k => $s) {
            $out[$k] = $s / max(1, $qty[$k]);
        }

        return $out;
    }

    public function toArray(): array
    {
        return [
            'total' => round($this->total(), 2),
            'items_total' => round($this->itemsTotal, 2),
            'shipping_total' => round($this->shippingTotal, 2),
            'vendor_count' => $this->vendorCount(),
            'complete' => $this->isComplete(),
            'vendors' => array_map(static fn (VendorAllocation $v) => $v->toArray(), $this->vendors),
            'strategy' => [
                'mode' => $this->mode,
                'proven_optimal' => $this->provenOptimal,
                'nodes_explored' => $this->nodesExplored,
                'duration_ms' => round($this->durationMs, 2),
            ],
            'alternatives' => array_map(static function (array $a): array {
                $a['total'] = $a['total'] === null ? null : round($a['total'], 2);

                return $a;
            }, $this->alternatives),
            'explanation' => $this->explanation,
            'excluded_offers' => $this->excludedOffers,
            'unavailable' => $this->unavailable,
        ];
    }
}

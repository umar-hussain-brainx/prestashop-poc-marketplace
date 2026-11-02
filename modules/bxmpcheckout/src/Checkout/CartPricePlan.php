<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Checkout;

use BrainX\Marketplace\Domain\Optimizer\AllocationResult;

/**
 * Pure: the cart-scoped SpecificPrice plan derived from an optimizer result — one fixed tax-excluded price per
 * (id_product, id_product_attribute) = quantity-weighted (blended) allocated unit price, rounded to 6 decimals
 * (SpecificPrice.price is DECIMAL(20,6)).
 *
 * When a line is split across vendors the blended price is generally not a whole cent; PrestaShop then re-derives the
 * line total from that unit price according to PS_ROUND_TYPE. `roundingReport()` tells the cent drift per line.
 */
final class CartPricePlan
{
    public const ROUND_ITEM = 1;
    public const ROUND_LINE = 2;
    public const ROUND_TOTAL = 3;

    /**
     * @param array<string, float> $unitPrices "idProduct-idAttr" => fixed unit price (tax excl.)
     * @param array<string, int>   $quantities
     * @param array<string, float> $allocatedTotals exact Σ allocated line totals per key
     */
    public function __construct(
        public readonly array $unitPrices,
        public readonly array $quantities,
        public readonly array $allocatedTotals,
    ) {
    }

    public static function fromResult(AllocationResult $result): self
    {
        $qty = [];
        $tot = [];
        foreach ($result->lines() as $l) {
            $k = $l->idProduct . '-' . $l->idProductAttribute;
            $qty[$k] = ($qty[$k] ?? 0) + $l->quantity;
            $tot[$k] = ($tot[$k] ?? 0) + $l->total();
        }
        $prices = [];
        foreach ($result->blendedUnitPrices() as $k => $p) {
            $prices[$k] = round($p, 6);
        }
        ksort($prices);

        return new self($prices, $qty, $tot);
    }

    /** @return array{0:int,1:int} [idProduct, idProductAttribute] */
    public static function splitKey(string $key): array
    {
        [$p, $a] = array_map('intval', explode('-', $key, 2)) + [0, 0];

        return [$p, $a];
    }

    /** True when every line is allocated at a single price (no blended unit price). */
    public function isExact(int $roundType = self::ROUND_LINE): bool
    {
        foreach ($this->roundingReport($roundType) as $r) {
            if (abs($r['delta']) >= 0.005) {
                return false;
            }
        }

        return true;
    }

    /**
     * What PrestaShop will charge per line vs. the optimizer total (tax excl.).
     *
     * @return array<string, array{quantity:int, unit_price:float, allocated_total:float, ps_line_total:float, delta:float}>
     */
    public function roundingReport(int $roundType = self::ROUND_LINE): array
    {
        $out = [];
        foreach ($this->unitPrices as $k => $unit) {
            $q = $this->quantities[$k] ?? 0;
            $ps = match ($roundType) {
                self::ROUND_ITEM => round(round($unit, 2) * $q, 2),
                self::ROUND_TOTAL => $unit * $q,
                default => round($unit * $q, 2),
            };
            $alloc = round($this->allocatedTotals[$k] ?? 0.0, 2);
            $out[$k] = ['quantity' => $q, 'unit_price' => $unit, 'allocated_total' => $alloc, 'ps_line_total' => round($ps, 6), 'delta' => round($ps - $alloc, 6)];
        }

        return $out;
    }
}

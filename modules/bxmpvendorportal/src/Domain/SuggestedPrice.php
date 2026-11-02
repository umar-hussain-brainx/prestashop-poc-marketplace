<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Domain;

/**
 * Suggested price for a vendor to become (or stay) the cheapest offer on an identical SKU.
 *
 * Inputs are effective unit prices, tax excluded. Result actions:
 *  - none   : no eligible competitor (vendor is alone on the SKU) — nothing to suggest
 *  - lower  : vendor is not strictly cheapest → suggest bestCompetitor − step (never below floor / minPrice)
 *  - keep   : vendor is strictly cheapest by at most one step → keep the price
 *  - raise  : vendor is strictly cheapest by more than one step → may raise up to bestCompetitor − step and stay cheapest
 *  - floor  : lowering is needed but would go below the floor (e.g. cost) → suggested = null, blocked
 */
final class SuggestedPrice
{
    public const NONE = 'none';
    public const LOWER = 'lower';
    public const KEEP = 'keep';
    public const RAISE = 'raise';
    public const FLOOR = 'floor';

    public function __construct(
        private readonly float $step = 0.01,
        private readonly float $minPrice = 0.01,
    ) {
        if ($step <= 0) {
            throw new \InvalidArgumentException('step must be > 0');
        }
    }

    /**
     * @return array{action:string, suggested:float|null, delta:float|null, delta_pct:float|null, gap:float|null}
     *         delta = suggested − own (negative = price cut); gap = own − bestCompetitor; delta_pct = gap / bestCompetitor · 100
     */
    public function suggest(float $ownPrice, ?float $bestCompetitorPrice, ?float $floor = null): array
    {
        if ($bestCompetitorPrice === null || $bestCompetitorPrice <= 0) {
            return ['action' => self::NONE, 'suggested' => null, 'delta' => null, 'delta_pct' => null, 'gap' => null];
        }
        $own = round($ownPrice, 2);
        $best = round($bestCompetitorPrice, 2);
        $gap = round($own - $best, 2);
        $gapPct = round($gap / $best * 100, 2);
        $target = round($best - $this->step, 2);
        $lowest = max($this->minPrice, $floor ?? 0.0);

        if ($own < $best) {
            if ($target - $own > $this->step / 2) {
                return ['action' => self::RAISE, 'suggested' => $target, 'delta' => round($target - $own, 2), 'delta_pct' => $gapPct, 'gap' => $gap];
            }

            return ['action' => self::KEEP, 'suggested' => $own, 'delta' => 0.0, 'delta_pct' => $gapPct, 'gap' => $gap];
        }
        if ($target < $lowest - 1e-9) {
            return ['action' => self::FLOOR, 'suggested' => null, 'delta' => null, 'delta_pct' => $gapPct, 'gap' => $gap];
        }

        return ['action' => self::LOWER, 'suggested' => $target, 'delta' => round($target - $own, 2), 'delta_pct' => $gapPct, 'gap' => $gap];
    }
}

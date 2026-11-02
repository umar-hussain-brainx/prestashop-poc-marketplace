<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Domain;

use BrainX\Marketplace\Domain\Pricing\ComparisonRow;
use BrainX\Marketplace\Domain\Pricing\PriceComparator;

/**
 * Competitive position of one vendor on one SKU, built on the core PriceComparator::vendorPosition()
 * (ranking without destination = by effective unit price, then stock).
 */
final class Positioning
{
    public function __construct(
        private readonly PriceComparator $comparator = new PriceComparator(),
        private readonly SuggestedPrice $suggester = new SuggestedPrice(),
    ) {
    }

    /**
     * @param ComparisonRow[] $rows comparison of ONE (id_product, id_product_attribute), own offer included
     *
     * @return array{rank:int|null, sellers:int, competitors:int, own_price:float|null, best_price:float|null,
     *               best_competitor_price:float|null, best_competitor_name:string|null, delta_pct:float|null,
     *               buy_box:bool, buy_box_vendor:string|null, reason:string|null, suggestion:array}
     */
    public function forVendor(array $rows, int $idVendor, ?float $ownPriceFallback = null): array
    {
        $pos = $this->comparator->vendorPosition($rows, $idVendor);
        $bestCompetitor = null;
        $bestName = null;
        $winner = null;
        $reason = null;
        $eligible = 0;
        foreach ($rows as $r) {
            if ($r->rank !== null) {
                ++$eligible;
            }
            if ($r->best) {
                $winner = $r->offer->vendorName ?? ('#' . $r->offer->idVendor);
            }
            if ($r->offer->idVendor === $idVendor) {
                $reason = $r->ineligibleReason;
                continue;
            }
            if ($r->rank !== null && ($bestCompetitor === null || $r->effectivePrice < $bestCompetitor)) {
                $bestCompetitor = $r->effectivePrice;
                $bestName = $r->offer->vendorName ?? ('#' . $r->offer->idVendor);
            }
        }
        $own = $pos['own_price'] ?? $ownPriceFallback;
        if ($pos['own_price'] === null && $reason === null) {
            $reason = 'inactive';
        }
        $deltaPct = ($own !== null && $bestCompetitor !== null && $bestCompetitor > 0)
            ? round(($own - $bestCompetitor) / $bestCompetitor * 100, 2) : null;

        return [
            'rank' => $pos['rank'],
            'sellers' => $eligible,
            'competitors' => $pos['competitors'],
            'own_price' => $own,
            'best_price' => $pos['best_price'],
            'best_competitor_price' => $bestCompetitor,
            'best_competitor_name' => $bestName,
            'delta_pct' => $deltaPct,
            'buy_box' => $pos['is_best'],
            'buy_box_vendor' => $winner,
            'reason' => $reason,
            'suggestion' => $own === null ? $this->suggester->suggest(0.0, null) : $this->suggester->suggest($own, $bestCompetitor),
        ];
    }
}

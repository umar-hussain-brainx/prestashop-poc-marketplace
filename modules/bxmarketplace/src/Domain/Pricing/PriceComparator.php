<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Pricing;

use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Optimizer\OfferCandidate;
use BrainX\Marketplace\Domain\Optimizer\ShippingPolicy;
use BrainX\Marketplace\Domain\Shipping\ShippingRuleResolver;

/**
 * Dynamic price comparison for identical SKUs (same id_product + id_product_attribute):
 *  - effective price = discount_price when inside its [discount_from, discount_to] window (open bounds allowed) and
 *    lower than the list price, otherwise price;
 *  - availability = active offer (and vendor) with stock ≥ requested quantity;
 *  - delivery eligibility = vendor shipping rules accept the destination (when a destination is given);
 *  - ranking of eligible offers by landed total (price·qty + standalone shipping), then effective price, then
 *    larger stock, then id. Ineligible offers are listed after, unranked.
 */
final class PriceComparator
{
    public function __construct(private readonly ShippingRuleResolver $resolver = new ShippingRuleResolver())
    {
    }

    public function isDiscountActive(OfferSnapshot $offer, ?\DateTimeInterface $now = null): bool
    {
        if ($offer->discountPrice === null || $offer->discountPrice <= 0 || $offer->discountPrice >= $offer->price) {
            return false;
        }
        $now = $now ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable();
        if ($offer->discountFrom !== null && $now < $offer->discountFrom) {
            return false;
        }
        if ($offer->discountTo !== null && $now > $offer->discountTo) {
            return false;
        }

        return true;
    }

    public function effectivePrice(OfferSnapshot $offer, ?\DateTimeInterface $now = null): float
    {
        return $this->isDiscountActive($offer, $now) ? (float) $offer->discountPrice : $offer->price;
    }

    /**
     * @param OfferSnapshot[]                $offers   offers of ONE product/variant
     * @param array<int, ShippingPolicy>|ShippingPolicy[] $policies (any keys; matched by idVendor)
     *
     * @return ComparisonRow[] eligible offers first (ranked), then ineligible
     */
    public function compare(array $offers, int $quantity = 1, ?Destination $destination = null, array $policies = [], ?\DateTimeInterface $now = null): array
    {
        $byVendor = [];
        foreach ($policies as $p) {
            $byVendor[$p->idVendor] = $p;
        }
        $quantity = max(1, $quantity);
        $rows = [];
        foreach ($offers as $offer) {
            $eff = $this->effectivePrice($offer, $now);
            $available = $offer->active && $offer->quantity >= $quantity;
            $deliverable = null;
            $shipping = null;
            if ($destination !== null) {
                $rules = isset($byVendor[$offer->idVendor]) ? $byVendor[$offer->idVendor]->rules : [];
                $shipping = $this->resolver->costFor($rules, $destination, $eff * $quantity);
                $deliverable = $shipping !== null;
            }
            $reason = null;
            if (!$offer->active) {
                $reason = 'inactive';
            } elseif ($offer->quantity < $quantity) {
                $reason = $offer->quantity <= 0 ? 'out_of_stock' : 'insufficient_stock';
            } elseif ($deliverable === false) {
                $reason = 'no_delivery_to_destination';
            }
            $rows[] = [
                'offer' => $offer, 'eff' => $eff, 'disc' => $eff < $offer->price, 'available' => $available,
                'deliverable' => $deliverable, 'shipping' => $shipping, 'landed' => $eff * $quantity + ($shipping ?? 0.0), 'reason' => $reason,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $ea = $a['reason'] === null ? 0 : 1;
            $eb = $b['reason'] === null ? 0 : 1;

            return [$ea, $a['landed'], $a['eff'], -$a['offer']->quantity, $a['offer']->idOffer]
                <=> [$eb, $b['landed'], $b['eff'], -$b['offer']->quantity, $b['offer']->idOffer];
        });
        $out = [];
        $rank = 0;
        foreach ($rows as $r) {
            $eligible = $r['reason'] === null;
            $out[] = new ComparisonRow(
                $r['offer'], $r['eff'], $r['disc'], $r['available'], $r['deliverable'], $r['shipping'], $r['landed'],
                $eligible ? ++$rank : null, $eligible && $rank === 1, $r['reason']
            );
        }

        return $out;
    }

    /** @param ComparisonRow[] $rows */
    public function best(array $rows): ?ComparisonRow
    {
        foreach ($rows as $r) {
            if ($r->best) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Competitive positioning of a vendor on one SKU (vendor dashboard).
     *
     * @param ComparisonRow[] $rows
     *
     * @return array{rank:int|null,competitors:int,best_price:float|null,own_price:float|null,gap_to_best:float|null,is_best:bool}
     */
    public function vendorPosition(array $rows, int $idVendor): array
    {
        $eligible = array_values(array_filter($rows, static fn (ComparisonRow $r) => $r->rank !== null));
        $bestPrice = $eligible === [] ? null : min(array_map(static fn (ComparisonRow $r) => $r->effectivePrice, $eligible));
        foreach ($rows as $r) {
            if ($r->offer->idVendor === $idVendor) {
                return [
                    'rank' => $r->rank,
                    'competitors' => max(0, count($eligible) - ($r->rank !== null ? 1 : 0)),
                    'best_price' => $bestPrice,
                    'own_price' => $r->effectivePrice,
                    'gap_to_best' => $bestPrice === null ? null : round($r->effectivePrice - $bestPrice, 6),
                    'is_best' => $r->best,
                ];
            }
        }

        return ['rank' => null, 'competitors' => count($eligible), 'best_price' => $bestPrice, 'own_price' => null, 'gap_to_best' => null, 'is_best' => false];
    }

    /**
     * Converts offers into optimizer candidates (active, in stock, effective price).
     *
     * @param OfferSnapshot[] $offers
     *
     * @return OfferCandidate[]
     */
    public function toCandidates(array $offers, ?\DateTimeInterface $now = null): array
    {
        $out = [];
        foreach ($offers as $o) {
            if (!$o->active || $o->quantity <= 0) {
                continue;
            }
            $out[] = new OfferCandidate($o->idOffer, $o->idVendor, $o->idProduct, $o->idProductAttribute, $this->effectivePrice($o, $now), $o->quantity, $o->vendorSku);
        }

        return $out;
    }
}

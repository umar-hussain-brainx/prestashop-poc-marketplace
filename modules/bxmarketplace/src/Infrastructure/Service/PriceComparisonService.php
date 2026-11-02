<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Service;

use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Pricing\ComparisonRow;
use BrainX\Marketplace\Domain\Pricing\OfferSnapshot;
use BrainX\Marketplace\Domain\Pricing\PriceComparator;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;
use BrainX\Marketplace\Infrastructure\Repository\ShippingRuleRepository;

/** DB-backed price comparison for a master product (all or one combination). */
final class PriceComparisonService
{
    private OfferRepository $offers;
    private ShippingRuleRepository $shipping;
    private PriceComparator $comparator;

    public function __construct(?OfferRepository $offers = null, ?ShippingRuleRepository $shipping = null, ?PriceComparator $comparator = null)
    {
        $this->offers = $offers ?? new OfferRepository();
        $this->shipping = $shipping ?? new ShippingRuleRepository();
        $this->comparator = $comparator ?? new PriceComparator();
    }

    public function comparator(): PriceComparator
    {
        return $this->comparator;
    }

    /**
     * @return array<int, ComparisonRow[]> id_product_attribute => ranked rows
     */
    public function compareProduct(int $idProduct, ?int $idProductAttribute = null, int $quantity = 1, ?Destination $destination = null, bool $includeInactive = false): array
    {
        $rows = $this->offers->findForProduct($idProduct, $idProductAttribute, !$includeInactive);
        $byAttr = [];
        $vendorIds = [];
        foreach ($rows as $r) {
            $byAttr[(int) $r['id_product_attribute']][] = OfferSnapshot::fromArray($r);
            $vendorIds[] = (int) $r['id_vendor'];
        }
        $policies = $destination ? $this->shipping->policiesFor(array_unique($vendorIds)) : [];
        $out = [];
        foreach ($byAttr as $attr => $snaps) {
            $out[$attr] = $this->comparator->compare($snaps, $quantity, $destination, $policies);
        }
        ksort($out);

        return $out;
    }

    /** Best eligible offer of a product/combination or null. */
    public function bestOffer(int $idProduct, int $idProductAttribute = 0, int $quantity = 1, ?Destination $destination = null): ?ComparisonRow
    {
        $rows = $this->compareProduct($idProduct, $idProductAttribute, $quantity, $destination)[$idProductAttribute] ?? [];

        return $this->comparator->best($rows);
    }
}

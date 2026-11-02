<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Infrastructure;

use BrainX\Marketplace\Domain\Pricing\OfferSnapshot;
use BrainX\Marketplace\Domain\Pricing\PriceComparator;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;
use BrainX\MarketplaceVendor\Domain\Positioning;

/**
 * Competitive positioning of a vendor's own offers against all active offers of identical SKUs
 * (same id_product + id_product_attribute), using the core PriceComparator (one query for all SKUs).
 */
final class PositioningService
{
    private PriceComparator $comparator;
    private Positioning $positioning;

    public function __construct(private readonly OfferRepository $offers = new OfferRepository())
    {
        $this->comparator = new PriceComparator();
        $this->positioning = new Positioning($this->comparator);
    }

    /**
     * @param array<int, array<string, mixed>> $ownOfferRows bxmp_offer rows of the vendor
     *
     * @return array<int, array<string, mixed>> id_offer => position (see Positioning::forVendor())
     */
    public function positionsFor(int $idVendor, array $ownOfferRows): array
    {
        $pairs = [];
        foreach ($ownOfferRows as $r) {
            $pairs[$r['id_product'] . '-' . $r['id_product_attribute']] = [(int) $r['id_product'], (int) $r['id_product_attribute']];
        }
        $groups = [];
        foreach ($this->offers->snapshotsForPairs(array_values($pairs)) as $snap) {
            $groups[$snap->idProduct . '-' . $snap->idProductAttribute][] = $snap;
        }
        $out = [];
        foreach ($ownOfferRows as $r) {
            $key = $r['id_product'] . '-' . $r['id_product_attribute'];
            $rows = $this->comparator->compare($groups[$key] ?? []);
            $ownEffective = $this->comparator->effectivePrice(OfferSnapshot::fromArray($r));
            $pos = $this->positioning->forVendor($rows, $idVendor, $ownEffective);
            $pos['discount_active'] = $this->comparator->isDiscountActive(OfferSnapshot::fromArray($r));
            $out[(int) $r['id_offer']] = $pos;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Service;

use BrainX\Marketplace\Domain\Optimizer\AllocationResult;
use BrainX\Marketplace\Domain\Optimizer\BasketLine;
use BrainX\Marketplace\Domain\Optimizer\CartOptimizer;
use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Pricing\PriceComparator;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;
use BrainX\Marketplace\Infrastructure\Repository\ShippingRuleRepository;

/**
 * PrestaShop-facing façade of the CartOptimizer: loads offers (effective prices via PriceComparator) and vendor
 * shipping policies from the DB, runs the pure-PHP optimizer and dispatches `actionBxmpCartOptimized`.
 *
 * Used by the internal API (POST cart/optimize) and by bxmpcheckout (actionCartSave).
 */
final class CartOptimizationService
{
    private OfferRepository $offers;
    private ShippingRuleRepository $shipping;
    private CartOptimizer $optimizer;
    private PriceComparator $comparator;

    public function __construct(
        ?OfferRepository $offers = null,
        ?ShippingRuleRepository $shipping = null,
        ?CartOptimizer $optimizer = null,
        ?PriceComparator $comparator = null,
    ) {
        $this->offers = $offers ?? new OfferRepository();
        $this->shipping = $shipping ?? new ShippingRuleRepository();
        $this->optimizer = $optimizer ?? self::defaultOptimizer();
        $this->comparator = $comparator ?? new PriceComparator();
    }

    /** Optimizer configured from module configuration (BXMP_OPT_* keys). */
    public static function defaultOptimizer(bool $allowPartial = false): CartOptimizer
    {
        $cfg = static fn (string $k, int $def): int => class_exists('Configuration', false) && \Configuration::get($k) ? (int) \Configuration::get($k) : $def;

        return new CartOptimizer(
            exactMaxLines: $cfg('BXMP_OPT_EXACT_MAX_LINES', 12),
            nodeBudget: $cfg('BXMP_OPT_NODE_BUDGET', 400000),
            allowPartial: $allowPartial,
        );
    }

    /**
     * @param array<int, BasketLine|array{id_product:int,id_product_attribute?:int,quantity:int}> $lines
     *
     * @throws \BrainX\Marketplace\Domain\Optimizer\UnavailableProductException
     */
    public function optimize(array $lines, Destination $destination, ?\DateTimeInterface $now = null): AllocationResult
    {
        $basket = [];
        $pairs = [];
        foreach ($lines as $l) {
            $line = $l instanceof BasketLine ? $l : new BasketLine((int) $l['id_product'], (int) ($l['id_product_attribute'] ?? 0), (int) $l['quantity']);
            $basket[] = $line;
            $pairs[$line->key()] = [$line->idProduct, $line->idProductAttribute];
        }
        $snapshots = $this->offers->snapshotsForPairs(array_values($pairs));
        $candidates = $this->comparator->toCandidates($snapshots, $now);
        $vendorIds = array_values(array_unique(array_map(static fn ($c) => $c->idVendor, $candidates)));
        $policies = $this->shipping->policiesFor($vendorIds);

        $result = $this->optimizer->optimize($basket, $candidates, array_values($policies), $destination);

        if (class_exists('Hook', false)) {
            \Hook::exec('actionBxmpCartOptimized', ['lines' => $basket, 'destination' => $destination, 'result' => $result]);
        }
        if ($result->durationMs > 1000) {
            Logger::channel('optimizer')->warning('Slow optimization', ['lines' => count($basket), 'ms' => $result->durationMs, 'mode' => $result->mode]);
        }

        return $result;
    }

    /**
     * Optimizes a PrestaShop cart for its delivery address (or the given destination).
     */
    public function optimizeCart(\Cart $cart, ?Destination $destination = null): AllocationResult
    {
        $lines = [];
        foreach ($cart->getProducts() as $p) {
            $lines[] = new BasketLine((int) $p['id_product'], (int) $p['id_product_attribute'], (int) $p['cart_quantity']);
        }
        $destination ??= self::destinationFromAddress((int) $cart->id_address_delivery);

        return $this->optimize($lines, $destination);
    }

    /** Destination from a PS address; falls back to the shop default country when there is no address. */
    public static function destinationFromAddress(int $idAddress): Destination
    {
        if ($idAddress > 0) {
            $a = new \Address($idAddress);
            if (\Validate::isLoadedObject($a)) {
                return new Destination((int) $a->id_country, (string) $a->postcode, \Country::getIsoById((int) $a->id_country) ?: null);
            }
        }
        $idCountry = (int) \Configuration::get('PS_COUNTRY_DEFAULT');

        return new Destination($idCountry, '', \Country::getIsoById($idCountry) ?: null);
    }

    /** Destination from API input: {id_country | country_iso, postcode}. */
    public static function destinationFromArray(array $d): Destination
    {
        $idCountry = (int) ($d['id_country'] ?? 0);
        $iso = isset($d['country_iso']) ? strtoupper((string) $d['country_iso']) : null;
        if ($idCountry <= 0 && $iso) {
            $idCountry = (int) \Country::getByIso($iso);
        }
        if ($idCountry <= 0) {
            $idCountry = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        }

        return new Destination($idCountry, (string) ($d['postcode'] ?? ''), $iso ?: (\Country::getIsoById($idCountry) ?: null));
    }
}

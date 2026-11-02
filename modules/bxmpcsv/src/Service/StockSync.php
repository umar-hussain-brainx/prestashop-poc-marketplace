<?php

declare(strict_types=1);

namespace BrainX\Csv\Service;

use BrainX\Csv\Config;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;

/**
 * Keeps PS StockAvailable aligned with the vendor offers (sum of active offers of active vendors), so that the
 * native catalog shows master products as available. Pairs are collected and flushed once per chunk.
 */
final class StockSync
{
    /** @var array<string, array{0:int,1:int}> */
    private array $pending = [];

    public function __construct(private readonly OfferRepository $offers = new OfferRepository())
    {
    }

    public function touch(int $idProduct, int $idProductAttribute): void
    {
        $this->pending[$idProduct . '-' . $idProductAttribute] = [$idProduct, $idProductAttribute];
    }

    public function flush(): int
    {
        if (!Config::bool('BXMPCSV_SYNC_STOCK') || $this->pending === []) {
            $this->pending = [];

            return 0;
        }
        $idShop = (int) \Context::getContext()->shop->id;
        $products = [];
        foreach ($this->pending as [$idP, $idA]) {
            if ($idA > 0) {
                \StockAvailable::setQuantity($idP, $idA, $this->offers->totalStock($idP, $idA), $idShop, false);
            }
            $products[$idP] = true;
        }
        foreach (array_keys($products) as $idP) {
            $total = (int) \Db::getInstance()->getValue('SELECT COALESCE(SUM(o.quantity),0) FROM `' . _DB_PREFIX_ . 'bxmp_offer` o
                INNER JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = o.id_vendor AND v.active = 1
                WHERE o.active = 1 AND o.id_product = ' . (int) $idP);
            $hasCombinations = (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product = ' . (int) $idP) > 0;
            if ($hasCombinations) {
                // product line of a product with combinations = sum of its combinations
                \Db::getInstance()->update('stock_available', ['quantity' => $total, 'physical_quantity' => $total], 'id_product = ' . (int) $idP . ' AND id_product_attribute = 0');
            } else {
                \StockAvailable::setQuantity((int) $idP, 0, $total, $idShop, false);
            }
        }
        $n = count($this->pending);
        $this->pending = [];

        return $n;
    }
}

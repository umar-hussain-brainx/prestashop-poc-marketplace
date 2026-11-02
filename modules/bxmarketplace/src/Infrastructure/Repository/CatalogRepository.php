<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Repository;

use BrainX\Marketplace\Domain\Matching\CatalogLookup;
use BrainX\Marketplace\Domain\Matching\MasterProduct;

/**
 * Master catalog lookup on native PS tables (product, product_attribute, manufacturer, product_lang).
 * Combinations are returned with their own EAN13/reference/MPN.
 */
final class CatalogRepository implements CatalogLookup
{
    public function __construct(private readonly int $idLang = 0)
    {
    }

    private function lang(): int
    {
        return $this->idLang ?: (int) \Configuration::get('PS_LANG_DEFAULT');
    }

    public function findByEan(string $ean13): array
    {
        $e = pSQL($ean13);

        return $this->query("p.ean13 = '{$e}'", "pa.ean13 = '{$e}'");
    }

    public function findByReference(string $reference): array
    {
        $r = pSQL(trim($reference));

        return $this->query("(p.reference = '{$r}' OR p.mpn = '{$r}')", "(pa.reference = '{$r}' OR pa.mpn = '{$r}')");
    }

    public function searchByTitle(string $title, ?string $brand, int $limit = 20): array
    {
        $words = array_slice(array_filter(preg_split('/\s+/', trim($title)) ?: [], static fn ($w) => mb_strlen($w) > 2), 0, 6);
        if ($words === []) {
            return [];
        }
        $like = implode(' OR ', array_map(static fn ($w) => "pl.name LIKE '%" . pSQL(addcslashes($w, '%_')) . "%'", $words));
        $rows = \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT p.id_product, 0 AS id_product_attribute, p.ean13, p.reference, p.mpn, m.name AS brand, pl.name
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $this->lang() . ' AND pl.id_shop = ' . (int) \Context::getContext()->shop->id . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.id_manufacturer = p.id_manufacturer
            WHERE ' . $like . ' LIMIT ' . max(1, $limit)) ?: [];

        return array_map([MasterProduct::class, 'fromArray'], $rows);
    }

    /** @return MasterProduct[] */
    private function query(string $productWhere, string $combinationWhere): array
    {
        $db = \Db::getInstance(_PS_USE_SQL_SLAVE_);
        $lang = $this->lang();
        $shop = (int) \Context::getContext()->shop->id;
        $rows = $db->executeS('SELECT p.id_product, 0 AS id_product_attribute, p.ean13, p.reference, p.mpn, m.name AS brand, pl.name
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $lang . ' AND pl.id_shop = ' . $shop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.id_manufacturer = p.id_manufacturer
            WHERE ' . $productWhere) ?: [];
        $rows = array_merge($rows, $db->executeS('SELECT pa.id_product, pa.id_product_attribute, pa.ean13, pa.reference, pa.mpn, m.name AS brand, pl.name
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = pa.id_product
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $lang . ' AND pl.id_shop = ' . $shop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.id_manufacturer = p.id_manufacturer
            WHERE ' . $combinationWhere) ?: []);

        return array_map([MasterProduct::class, 'fromArray'], $rows);
    }
}

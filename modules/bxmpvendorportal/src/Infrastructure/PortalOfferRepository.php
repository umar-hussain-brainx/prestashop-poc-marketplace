<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Infrastructure;

/**
 * Vendor-scoped offer reads for the portal (own offers + master product name / EAN / combination label) and
 * master-catalog lookups for "add offer". Writes go through the core OfferRepository::upsert().
 */
final class PortalOfferRepository
{
    public function __construct(private readonly int $idLang, private readonly int $idShop)
    {
    }

    private function db(): \Db
    {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_);
    }

    private function p(): string
    {
        return _DB_PREFIX_;
    }

    private function baseSelect(): string
    {
        $p = $this->p();

        return 'SELECT o.*, pl.name AS product_name, p.reference AS product_reference, p.active AS product_active,
                COALESCE(NULLIF(pa.ean13, \'\'), NULLIF(p.ean13, \'\'), o.ean13) AS master_ean13
            FROM `' . $p . 'bxmp_offer` o
            LEFT JOIN `' . $p . 'product` p ON p.id_product = o.id_product
            LEFT JOIN `' . $p . 'product_lang` pl ON pl.id_product = o.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . '
            LEFT JOIN `' . $p . 'product_attribute` pa ON pa.id_product_attribute = o.id_product_attribute AND o.id_product_attribute > 0';
    }

    /**
     * @param array{q?:string, status?:string} $filters status: active|inactive|out_of_stock|discount
     */
    private function where(int $idVendor, array $filters): string
    {
        $w = ['o.id_vendor = ' . (int) $idVendor];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '\'%' . pSQL(addcslashes($q, '%_')) . '%\'';
            $eq = '\'' . pSQL($q) . '\'';
            $w[] = '(pl.name LIKE ' . $like . ' OR o.vendor_sku LIKE ' . $like . ' OR p.reference LIKE ' . $like
                . ' OR o.ean13 = ' . $eq . ' OR p.ean13 = ' . $eq . ' OR pa.ean13 = ' . $eq . ')';
        }
        switch ($filters['status'] ?? '') {
            case 'active': $w[] = 'o.active = 1';
                break;
            case 'inactive': $w[] = 'o.active = 0';
                break;
            case 'out_of_stock': $w[] = 'o.quantity <= 0';
                break;
            case 'discount': $w[] = 'o.discount_price IS NOT NULL';
                break;
        }

        return implode(' AND ', $w);
    }

    public function listOwn(int $idVendor, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $rows = $this->db()->executeS($this->baseSelect() . ' WHERE ' . $this->where($idVendor, $filters)
            . ' ORDER BY pl.name, o.id_product, o.id_product_attribute LIMIT ' . max(0, $offset) . ', ' . max(1, min(1000, $limit))) ?: [];

        return $this->withCombinationNames($rows);
    }

    public function countOwn(int $idVendor, array $filters = []): int
    {
        $p = $this->p();

        return (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . $p . 'bxmp_offer` o
            LEFT JOIN `' . $p . 'product` p ON p.id_product = o.id_product
            LEFT JOIN `' . $p . 'product_lang` pl ON pl.id_product = o.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . '
            LEFT JOIN `' . $p . 'product_attribute` pa ON pa.id_product_attribute = o.id_product_attribute AND o.id_product_attribute > 0
            WHERE ' . $this->where($idVendor, $filters));
    }

    /** One offer, only if it belongs to the vendor (null otherwise — never leaks another vendor's row). */
    public function findOwn(int $idVendor, int $idOffer): ?array
    {
        $row = $this->db()->getRow($this->baseSelect() . ' WHERE o.id_offer = ' . (int) $idOffer . ' AND o.id_vendor = ' . (int) $idVendor);

        return $row ? $this->withCombinationNames([$row])[0] : null;
    }

    /** Own offer ids keyed by vendor SKU and by EAN (offer or master EAN) — for bulk stock updates. */
    public function ownKeyIndex(int $idVendor): array
    {
        $index = [];
        foreach ($this->db()->executeS($this->baseSelect() . ' WHERE o.id_vendor = ' . (int) $idVendor) ?: [] as $r) {
            foreach ([$r['vendor_sku'], $r['ean13'], $r['master_ean13']] as $k) {
                if ($k !== null && $k !== '') {
                    $index[(string) $k][] = (int) $r['id_offer'];
                }
            }
        }

        return array_map(static fn (array $ids) => array_values(array_unique($ids)), $index);
    }

    /**
     * Master catalog search (native products / combinations) by EAN (exact) or name / reference (LIKE).
     * Annotated with the number of active sellers, best price and whether the vendor already has an offer.
     */
    public function searchMaster(int $idVendor, string $query, int $limit = 30): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return [];
        }
        $p = $this->p();
        if (preg_match('/^\d{8,14}$/', $query)) {
            $cond = '(p.ean13 = \'' . pSQL($query) . '\' OR pa.ean13 = \'' . pSQL($query) . '\')';
        } else {
            $like = '\'%' . pSQL(addcslashes($query, '%_')) . '%\'';
            $cond = '(pl.name LIKE ' . $like . ' OR p.reference LIKE ' . $like . ' OR pa.reference LIKE ' . $like . ')';
        }
        $rows = $this->db()->executeS('SELECT p.id_product, COALESCE(pa.id_product_attribute, 0) AS id_product_attribute, pl.name AS product_name,
                p.reference, COALESCE(NULLIF(pa.ean13, \'\'), p.ean13) AS ean13, p.price AS catalog_price
            FROM `' . $p . 'product` p
            INNER JOIN `' . $p . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . '
            LEFT JOIN `' . $p . 'product_attribute` pa ON pa.id_product = p.id_product
            WHERE p.active = 1 AND ' . $cond . '
            ORDER BY pl.name, p.id_product, id_product_attribute LIMIT ' . max(1, min(100, $limit))) ?: [];
        $rows = $this->withCombinationNames($rows);
        foreach ($rows as &$r) {
            $stats = $this->db()->getRow('SELECT COUNT(*) AS sellers, MIN(LEAST(o.price, COALESCE(o.discount_price, o.price))) AS best,
                    SUM(o.id_vendor = ' . (int) $idVendor . ') AS mine
                FROM `' . $p . 'bxmp_offer` o INNER JOIN `' . $p . 'bxmp_vendor` v ON v.id_vendor = o.id_vendor AND v.active = 1
                WHERE o.active = 1 AND o.id_product = ' . (int) $r['id_product'] . ' AND o.id_product_attribute = ' . (int) $r['id_product_attribute']) ?: [];
            $r['sellers'] = (int) ($stats['sellers'] ?? 0);
            $r['best_price'] = isset($stats['best']) ? (float) $stats['best'] : null;
            $r['own_offer_id'] = \BxmpOffer::findId($idVendor, (int) $r['id_product'], (int) $r['id_product_attribute']);
        }

        return $rows;
    }

    /** Validates that (id_product, id_product_attribute) is an existing active master product / combination. */
    public function masterExists(int $idProduct, int $idProductAttribute): bool
    {
        $p = $this->p();
        if (!(int) $this->db()->getValue('SELECT id_product FROM `' . $p . 'product` WHERE active = 1 AND id_product = ' . (int) $idProduct)) {
            return false;
        }
        $hasCombinations = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . $p . 'product_attribute` WHERE id_product = ' . (int) $idProduct) > 0;
        if ($idProductAttribute === 0) {
            return !$hasCombinations;
        }

        return (bool) $this->db()->getValue('SELECT id_product_attribute FROM `' . $p . 'product_attribute` WHERE id_product = ' . (int) $idProduct . ' AND id_product_attribute = ' . (int) $idProductAttribute);
    }

    public function masterEan(int $idProduct, int $idProductAttribute): ?string
    {
        $p = $this->p();
        $ean = $idProductAttribute > 0
            ? $this->db()->getValue('SELECT ean13 FROM `' . $p . 'product_attribute` WHERE id_product_attribute = ' . (int) $idProductAttribute)
            : '';
        if (!$ean) {
            $ean = $this->db()->getValue('SELECT ean13 FROM `' . $p . 'product` WHERE id_product = ' . (int) $idProduct);
        }

        return $ean ? (string) $ean : null;
    }

    /** Adds `combination` (e.g. "Size: S, Color: White") to rows having id_product_attribute. */
    public function withCombinationNames(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['id_product_attribute'] ?? 0), $rows))));
        $names = [];
        if ($ids !== []) {
            $p = $this->p();
            foreach ($this->db()->executeS('SELECT pac.id_product_attribute,
                    GROUP_CONCAT(CONCAT(agl.public_name, \': \', al.name) ORDER BY ag.position SEPARATOR \', \') AS label
                FROM `' . $p . 'product_attribute_combination` pac
                INNER JOIN `' . $p . 'attribute` a ON a.id_attribute = pac.id_attribute
                INNER JOIN `' . $p . 'attribute_group` ag ON ag.id_attribute_group = a.id_attribute_group
                INNER JOIN `' . $p . 'attribute_lang` al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $this->idLang . '
                INNER JOIN `' . $p . 'attribute_group_lang` agl ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . $this->idLang . '
                WHERE pac.id_product_attribute IN (' . implode(',', $ids) . ')
                GROUP BY pac.id_product_attribute') ?: [] as $r) {
                $names[(int) $r['id_product_attribute']] = (string) $r['label'];
            }
        }
        foreach ($rows as &$r) {
            $r['combination'] = $names[(int) ($r['id_product_attribute'] ?? 0)] ?? '';
        }

        return $rows;
    }

    /** Re-syncs native StockAvailable with the sum of active vendor stock (same rule as the core seeder). */
    public static function syncNativeStock(int $idProduct, int $idProductAttribute, int $idShop): void
    {
        try {
            $total = (new \BrainX\Marketplace\Infrastructure\Repository\OfferRepository())->totalStock($idProduct, $idProductAttribute);
            \StockAvailable::setQuantity($idProduct, $idProductAttribute, $total, $idShop);
        } catch (\Throwable $e) {
            \BrainX\Marketplace\Infrastructure\Logger::channel('vendor')->warning('Stock sync failed: ' . $e->getMessage(), ['id_product' => $idProduct, 'id_product_attribute' => $idProductAttribute]);
        }
    }
}

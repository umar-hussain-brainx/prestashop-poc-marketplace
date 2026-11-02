<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Repository;

use BrainX\Marketplace\Domain\Pricing\OfferSnapshot;

/**
 * bxmp_offer access. Rows returned include vendor_name / vendor_code / vendor_active.
 */
final class OfferRepository
{
    private const SELECT = 'SELECT o.*, v.name AS vendor_name, v.code AS vendor_code, v.active AS vendor_active
        FROM `%1$sbxmp_offer` o INNER JOIN `%1$sbxmp_vendor` v ON v.id_vendor = o.id_vendor';

    private function db(): \Db
    {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_);
    }

    private function select(): string
    {
        return sprintf(self::SELECT, _DB_PREFIX_);
    }

    public function find(int $idOffer): ?array
    {
        $row = $this->db()->getRow($this->select() . ' WHERE o.id_offer = ' . (int) $idOffer);

        return $row ?: null;
    }

    /**
     * @param array{id_vendor?:int,id_product?:int,id_product_attribute?:int,active?:bool,ean13?:string} $filters
     */
    public function search(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1'];
        foreach (['id_vendor', 'id_product', 'id_product_attribute'] as $f) {
            if (isset($filters[$f])) {
                $where[] = 'o.' . $f . ' = ' . (int) $filters[$f];
            }
        }
        if (isset($filters['active'])) {
            $where[] = 'o.active = ' . ((bool) $filters['active'] ? 1 : 0);
        }
        if (!empty($filters['ean13'])) {
            $where[] = 'o.ean13 = \'' . pSQL((string) $filters['ean13']) . '\'';
        }

        return $this->db()->executeS($this->select() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY o.id_product, o.id_product_attribute, o.price LIMIT ' . max(0, $offset) . ', ' . max(1, min(1000, $limit))) ?: [];
    }

    public function count(array $filters = []): int
    {
        $where = ['1'];
        foreach (['id_vendor', 'id_product', 'id_product_attribute'] as $f) {
            if (isset($filters[$f])) {
                $where[] = $f . ' = ' . (int) $filters[$f];
            }
        }

        return (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_offer` WHERE ' . implode(' AND ', $where));
    }

    /** Offers of one product (optionally one combination). */
    public function findForProduct(int $idProduct, ?int $idProductAttribute = null, bool $activeOnly = true): array
    {
        return $this->db()->executeS($this->select() . ' WHERE o.id_product = ' . (int) $idProduct
            . ($idProductAttribute !== null ? ' AND o.id_product_attribute = ' . (int) $idProductAttribute : '')
            . ($activeOnly ? ' AND o.active = 1 AND v.active = 1' : '')
            . ' ORDER BY o.id_product_attribute, o.price') ?: [];
    }

    /**
     * Active offers (active vendors) for a set of exact (id_product, id_product_attribute) pairs.
     *
     * @param array<int, array{0:int,1:int}> $pairs
     *
     * @return OfferSnapshot[]
     */
    public function snapshotsForPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $cond = [];
        foreach ($pairs as [$p, $a]) {
            $cond[] = '(o.id_product = ' . (int) $p . ' AND o.id_product_attribute = ' . (int) $a . ')';
        }
        $rows = $this->db()->executeS($this->select() . ' WHERE o.active = 1 AND v.active = 1 AND (' . implode(' OR ', array_unique($cond)) . ')') ?: [];

        return array_map([OfferSnapshot::class, 'fromArray'], $rows);
    }

    /**
     * Insert or update the offer identified by (id_vendor, id_product, id_product_attribute).
     *
     * @return int id_offer
     */
    public function upsert(array $data): int
    {
        $idVendor = (int) $data['id_vendor'];
        $idProduct = (int) $data['id_product'];
        $idAttr = (int) ($data['id_product_attribute'] ?? 0);
        $id = \BxmpOffer::findId($idVendor, $idProduct, $idAttr);
        $offer = new \BxmpOffer($id ?: null);
        $offer->id_vendor = $idVendor;
        $offer->id_product = $idProduct;
        $offer->id_product_attribute = $idAttr;
        foreach (['vendor_sku', 'ean13', 'price', 'discount_price', 'discount_from', 'discount_to', 'quantity', 'condition', 'active'] as $f) {
            if (array_key_exists($f, $data)) {
                $offer->{$f} = ($data[$f] === '' && in_array($f, ['discount_price', 'discount_from', 'discount_to', 'ean13', 'vendor_sku'], true)) ? null : $data[$f];
            }
        }
        if (!$id && $offer->active === null) {
            $offer->active = true;
        }
        $errors = $offer->validateFields(false, true);
        if ($errors !== true) {
            throw new \InvalidArgumentException(is_string($errors) ? strip_tags($errors) : 'Invalid offer');
        }
        if (!$offer->save()) {
            throw new \RuntimeException('Could not save offer');
        }

        return (int) $offer->id;
    }

    /** Decrement vendor stock after an order (never below 0). */
    public function decrementStock(int $idOffer, int $quantity): void
    {
        \Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'bxmp_offer` SET quantity = GREATEST(0, quantity - ' . (int) $quantity . '), date_upd = NOW() WHERE id_offer = ' . (int) $idOffer);
    }

    /** Sum of active vendor stock for a product/combination (to sync PS StockAvailable). */
    public function totalStock(int $idProduct, int $idProductAttribute = 0): int
    {
        return (int) $this->db()->getValue('SELECT COALESCE(SUM(o.quantity),0) FROM `' . _DB_PREFIX_ . 'bxmp_offer` o
            INNER JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = o.id_vendor AND v.active = 1
            WHERE o.active = 1 AND o.id_product = ' . (int) $idProduct . ' AND o.id_product_attribute = ' . (int) $idProductAttribute);
    }
}

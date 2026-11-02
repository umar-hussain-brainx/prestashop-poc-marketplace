<?php

declare(strict_types=1);

namespace BrainX\Collections\Repository;

use BrainX\Collections\Domain\ScheduleResolver;

/**
 * Collections read/write model on the core tables bxmp_collection, bxmp_collection_product, bxmp_collection_category.
 * Visibility is always evaluated with ScheduleResolver at read time (works without cron).
 */
final class CollectionRepository
{
    private \Db $db;

    public function __construct(?\Db $db = null)
    {
        $this->db = $db ?? \Db::getInstance();
    }

    private static function t(string $table): string
    {
        return '`' . _DB_PREFIX_ . $table . '`';
    }

    /** All collections with their schedule status (+ explicit product / category counts). */
    public function all(?\DateTimeInterface $now = null): array
    {
        $rows = $this->db->executeS('SELECT c.*,
                (SELECT COUNT(*) FROM ' . self::t('bxmp_collection_product') . ' cp WHERE cp.id_collection = c.id_collection) AS products_count,
                (SELECT COUNT(*) FROM ' . self::t('bxmp_collection_category') . ' cc WHERE cc.id_collection = c.id_collection) AS categories_count
            FROM ' . self::t('bxmp_collection') . ' c ORDER BY c.position, c.id_collection') ?: [];
        foreach ($rows as &$r) {
            $r['status'] = ScheduleResolver::statusOf($r, $now);
        }

        return $rows;
    }

    /** Currently live collections, ordered by position. */
    public function live(?\DateTimeInterface $now = null): array
    {
        return array_values(array_filter($this->all($now), static fn ($r) => $r['status'] === ScheduleResolver::LIVE));
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->getRow('SELECT * FROM ' . self::t('bxmp_collection') . " WHERE slug = '" . pSQL($slug) . "'");

        return $row ?: null;
    }

    public function find(int $id): ?array
    {
        $row = $this->db->getRow('SELECT * FROM ' . self::t('bxmp_collection') . ' WHERE id_collection = ' . (int) $id);

        return $row ?: null;
    }

    /**
     * Resolved, visible product ids: explicit products first (by position), then the products of the assigned
     * categories and their sub-categories (by category position). Inactive / not-visible products are skipped.
     *
     * @return int[]
     */
    public function resolveProductIds(int $idCollection, int $limit = 0, ?int $idShop = null): array
    {
        $idShop = $idShop ?? (int) \Context::getContext()->shop->id ?: 1;
        $visible = 'ps.active = 1 AND ps.visibility IN (\'both\', \'catalog\') AND ps.id_shop = ' . (int) $idShop;
        $explicit = $this->db->executeS('SELECT cp.id_product FROM ' . self::t('bxmp_collection_product') . ' cp
            INNER JOIN ' . self::t('product_shop') . ' ps ON ps.id_product = cp.id_product
            WHERE cp.id_collection = ' . (int) $idCollection . ' AND ' . $visible . ' ORDER BY cp.position, cp.id_product') ?: [];
        $fromCategories = $this->db->executeS('SELECT cpr.id_product, MIN(cpr.position) AS pos FROM ' . self::t('bxmp_collection_category') . ' cc
            INNER JOIN ' . self::t('category') . ' root ON root.id_category = cc.id_category
            INNER JOIN ' . self::t('category') . ' c ON c.nleft >= root.nleft AND c.nright <= root.nright AND c.active = 1
            INNER JOIN ' . self::t('category_product') . ' cpr ON cpr.id_category = c.id_category
            INNER JOIN ' . self::t('product_shop') . ' ps ON ps.id_product = cpr.id_product
            WHERE cc.id_collection = ' . (int) $idCollection . ' AND ' . $visible . '
            GROUP BY cpr.id_product ORDER BY pos, cpr.id_product') ?: [];
        $ids = [];
        foreach ([...$explicit, ...$fromCategories] as $r) {
            $ids[(int) $r['id_product']] = true;
        }
        $ids = array_keys($ids);

        return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
    }

    /** Explicit products with names / references for the BO. */
    public function products(int $idCollection, int $idLang): array
    {
        return $this->db->executeS('SELECT cp.id_product, cp.position, pl.name, p.reference, p.ean13, p.active FROM ' . self::t('bxmp_collection_product') . ' cp
            INNER JOIN ' . self::t('product') . ' p ON p.id_product = cp.id_product
            LEFT JOIN ' . self::t('product_lang') . ' pl ON pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = 1
            WHERE cp.id_collection = ' . (int) $idCollection . ' ORDER BY cp.position, cp.id_product') ?: [];
    }

    /** @return int[] */
    public function categoryIds(int $idCollection): array
    {
        return array_map('intval', array_column($this->db->executeS('SELECT id_category FROM ' . self::t('bxmp_collection_category') . ' WHERE id_collection = ' . (int) $idCollection) ?: [], 'id_category'));
    }

    public function addProduct(int $idCollection, int $idProduct): void
    {
        $pos = (int) $this->db->getValue('SELECT COALESCE(MAX(position) + 1, 0) FROM ' . self::t('bxmp_collection_product') . ' WHERE id_collection = ' . (int) $idCollection);
        $this->db->insert('bxmp_collection_product', ['id_collection' => $idCollection, 'id_product' => $idProduct, 'position' => $pos], false, true, \Db::INSERT_IGNORE);
    }

    public function removeProduct(int $idCollection, int $idProduct): void
    {
        $this->db->delete('bxmp_collection_product', 'id_collection = ' . (int) $idCollection . ' AND id_product = ' . (int) $idProduct);
    }

    /** Move an explicit product up (-1) or down (+1); positions are renumbered 0..n. */
    public function moveProduct(int $idCollection, int $idProduct, int $direction): void
    {
        $ids = array_map('intval', array_column($this->db->executeS('SELECT id_product FROM ' . self::t('bxmp_collection_product') . ' WHERE id_collection = ' . (int) $idCollection . ' ORDER BY position, id_product') ?: [], 'id_product'));
        $i = array_search($idProduct, $ids, true);
        $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }
        $this->setProducts($idCollection, $ids);
    }

    /** @param int[] $productIds ordered */
    public function setProducts(int $idCollection, array $productIds): void
    {
        $this->db->delete('bxmp_collection_product', 'id_collection = ' . (int) $idCollection);
        $pos = 0;
        foreach (array_unique(array_map('intval', $productIds)) as $id) {
            if ($id > 0) {
                $this->db->insert('bxmp_collection_product', ['id_collection' => $idCollection, 'id_product' => $id, 'position' => $pos++]);
            }
        }
    }

    /** @param int[] $categoryIds */
    public function setCategories(int $idCollection, array $categoryIds): void
    {
        $this->db->delete('bxmp_collection_category', 'id_collection = ' . (int) $idCollection);
        foreach (array_unique(array_map('intval', $categoryIds)) as $id) {
            if ($id > 0) {
                $this->db->insert('bxmp_collection_category', ['id_collection' => $idCollection, 'id_category' => $id]);
            }
        }
    }

    public function deleteLinks(int $idCollection): void
    {
        $this->db->delete('bxmp_collection_product', 'id_collection = ' . (int) $idCollection);
        $this->db->delete('bxmp_collection_category', 'id_collection = ' . (int) $idCollection);
        $this->db->delete('bxmp_collection_state', 'id_collection = ' . (int) $idCollection);
    }

    /** Product search by name, reference, EAN13 (product or combination) or id. */
    public function searchProducts(string $q, int $idLang, int $limit = 30): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . pSQL(str_replace(['%', '_'], ['\\%', '\\_'], $q)) . '%';
        $exact = pSQL($q);

        return $this->db->executeS('SELECT DISTINCT p.id_product, pl.name, p.reference, p.ean13, p.active FROM ' . self::t('product') . ' p
            LEFT JOIN ' . self::t('product_lang') . ' pl ON pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = 1
            LEFT JOIN ' . self::t('product_attribute') . " pa ON pa.id_product = p.id_product
            WHERE pl.name LIKE '$like' OR p.reference LIKE '$like' OR p.ean13 = '$exact' OR pa.ean13 = '$exact' OR pa.reference = '$exact'"
            . (ctype_digit($q) ? ' OR p.id_product = ' . (int) $q : '') . '
            ORDER BY pl.name LIMIT ' . max(1, $limit)) ?: [];
    }

    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        return (bool) $this->db->getValue('SELECT 1 FROM ' . self::t('bxmp_collection') . " WHERE slug = '" . pSQL($slug) . "' AND id_collection <> " . (int) $exceptId);
    }
}

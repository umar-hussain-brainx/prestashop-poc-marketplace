<?php

declare(strict_types=1);

namespace BrainX\Collections\Repository;

/**
 * Vendor category label → PrestaShop category mapping (table bxmp_category_map, owned by bxmpcollections).
 *
 * Public API for other modules (e.g. the bxmpcsv importer):
 *
 *   require_once _PS_MODULE_DIR_ . 'bxmpcollections/vendor/autoload.php';
 *   $idCategory = (new \BrainX\Collections\Repository\CategoryMapRepository())->resolve('Tazze & Mug', $idVendor); // ?int
 *
 * Resolution: vendor-specific mapping first, then the global one (id_vendor = 0), on a normalised label key
 * (lower-case, accents stripped, punctuation collapsed). Mappings to deleted categories are ignored.
 */
final class CategoryMapRepository
{
    private \Db $db;

    public function __construct(?\Db $db = null)
    {
        $this->db = $db ?? \Db::getInstance();
    }

    public static function key(string $label): string
    {
        $s = mb_strtolower(trim($label), 'UTF-8');
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;
        $s = $t !== false && $t !== '' ? strtolower($t) : $s;
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    public function resolve(string $label, ?int $idVendor = null): ?int
    {
        $key = self::key($label);
        if ($key === '') {
            return null;
        }
        $id = (int) $this->db->getValue('SELECT m.id_category FROM `' . _DB_PREFIX_ . 'bxmp_category_map` m
            INNER JOIN `' . _DB_PREFIX_ . "category` c ON c.id_category = m.id_category
            WHERE m.label_key = '" . pSQL($key) . "' AND m.id_vendor IN (0, " . (int) $idVendor . ')
            ORDER BY m.id_vendor DESC');

        return $id ?: null;
    }

    public function all(int $idLang): array
    {
        return $this->db->executeS('SELECT m.*, cl.name AS category_name, v.name AS vendor_name FROM `' . _DB_PREFIX_ . 'bxmp_category_map` m
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = m.id_category AND cl.id_lang = ' . (int) $idLang . ' AND cl.id_shop = 1
            LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = m.id_vendor
            ORDER BY m.id_vendor, m.label') ?: [];
    }

    /** Insert or update (same vendor + normalised label). Returns the mapping id. */
    public function save(string $label, int $idCategory, int $idVendor = 0): int
    {
        $key = self::key($label);
        if ($key === '' || $idCategory <= 0) {
            throw new \InvalidArgumentException('Label and category are required');
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) $this->db->getValue('SELECT id_category_map FROM `' . _DB_PREFIX_ . 'bxmp_category_map` WHERE id_vendor = ' . (int) $idVendor . " AND label_key = '" . pSQL($key) . "'");
        if ($id) {
            $this->db->update('bxmp_category_map', ['label' => pSQL(trim($label)), 'id_category' => $idCategory, 'date_upd' => $now], 'id_category_map = ' . $id);

            return $id;
        }
        $this->db->insert('bxmp_category_map', ['id_vendor' => $idVendor, 'label' => pSQL(trim($label)), 'label_key' => pSQL($key), 'id_category' => $idCategory, 'date_add' => $now, 'date_upd' => $now]);

        return (int) $this->db->Insert_ID();
    }

    public function delete(int $id): void
    {
        $this->db->delete('bxmp_category_map', 'id_category_map = ' . (int) $id);
    }

    /** @return array<int, int> id_category => number of mappings */
    public function countsByCategory(): array
    {
        $out = [];
        foreach ($this->db->executeS('SELECT id_category, COUNT(*) AS n FROM `' . _DB_PREFIX_ . 'bxmp_category_map` GROUP BY id_category') ?: [] as $r) {
            $out[(int) $r['id_category']] = (int) $r['n'];
        }

        return $out;
    }
}

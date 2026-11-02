<?php
/**
 * Demo thematic collections (idempotent upsert by slug). Windows are relative to "now" so the demo stays meaningful:
 *   Christmas        live, ends in 60 days          Easter          scheduled (+140 d → +165 d) → hidden
 *   Halloween        expired (−33 d → −2 d) → hidden All Saints' Day expired (−8 d → −1 d) → hidden
 *   Carnival         scheduled (+95 d → +110 d)      Breakfast / Aperitivo  always on (no window)
 * Products come from the PrestaShop demo catalog (matched by reference) and the marketplace demo products.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use BrainX\Collections\Repository\CollectionRepository;

final class BxmpDemoCollections
{
    /** slug => [name, description, start offset (days|null), end offset (days|null), product references, category names] */
    public static function definitions(): array
    {
        return [
            'christmas' => ['Christmas', '<p>Gift ideas for the holidays: cosy cushions, framed posters and mugs from all our vendors.</p>', -1, 60,
                ['demo_6', 'demo_21', 'demo_12'], ['Home Accessories']],
            'breakfast' => ['Breakfast', '<p>Start the day right: mugs and everything for a slow breakfast.</p>', null, null,
                ['demo_11', 'demo_12', 'demo_13', 'demo_14', 'BXMP-P1'], []],
            'aperitivo' => ['Aperitivo', '<p>The Italian aperitivo hour: comfy cushions, good vibes and marketplace bestsellers.</p>', null, null,
                ['BXMP-P1', 'BXMP-P2', 'demo_15', 'demo_16', 'demo_17'], []],
            'easter' => ['Easter', '<p>Spring colours and Easter gifts.</p>', 140, 165, ['demo_1', 'demo_3'], []],
            'halloween' => ['Halloween', '<p>Spooky picks for October 31st.</p>', -33, -2, ['demo_18', 'demo_19'], ['Art']],
            'carnival' => ['Carnival', '<p>Masks, colours and party essentials.</p>', 95, 110, ['demo_7', 'demo_20'], []],
            'all-saints-day' => ["All Saints' Day", '<p>A quiet long weekend at home.</p>', -8, -1, ['demo_8', 'demo_9', 'demo_10'], ['Stationery']],
        ];
    }

    public static function seed(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $db = Db::getInstance();
        $repo = new CollectionRepository();
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $out = [];
        $pos = 0;
        foreach (self::definitions() as $slug => [$name, $desc, $start, $end, $refs, $cats]) {
            $id = (int) $db->getValue('SELECT id_collection FROM `' . _DB_PREFIX_ . "bxmp_collection` WHERE slug = '" . pSQL($slug) . "'");
            $c = new BxmpCollection($id ?: null);
            $c->name = $name;
            $c->slug = $slug;
            $c->description = $desc;
            $c->active = true;
            $c->date_start = $start === null ? null : $now->modify(($start >= 0 ? '+' : '') . $start . ' days')->format('Y-m-d 00:00:00');
            $c->date_end = $end === null ? null : $now->modify(($end >= 0 ? '+' : '') . $end . ' days')->format('Y-m-d 23:59:59');
            $c->position = $pos++;
            $c->save();
            $productIds = [];
            foreach ($refs as $ref) {
                if ($idp = (int) $db->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . "product` WHERE reference = '" . pSQL($ref) . "'")) {
                    $productIds[] = $idp;
                }
            }
            $repo->setProducts((int) $c->id, $productIds);
            $catIds = [];
            foreach ($cats as $catName) {
                if ($idc = (int) $db->getValue('SELECT id_category FROM `' . _DB_PREFIX_ . "category_lang` WHERE id_lang = $idLang AND name = '" . pSQL($catName) . "'")) {
                    $catIds[] = $idc;
                }
            }
            $repo->setCategories((int) $c->id, $catIds);
            $out[$slug] = ['id' => (int) $c->id, 'start' => $c->date_start, 'end' => $c->date_end, 'products' => count($productIds), 'categories' => count($catIds)];
        }

        return $out;
    }
}

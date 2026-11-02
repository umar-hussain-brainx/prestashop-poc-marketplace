<?php

declare(strict_types=1);

namespace BrainX\Csv\Catalog;

use BrainX\Csv\Config;
use BrainX\Csv\Validation\OfferRowMapper;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType;

/**
 * Creates master products (native PS products / combinations) from vendor rows:
 * EAN, reference / MPN, brand → Manufacturer (created when missing), name, description, category path
 * (created when missing, if allowed), default price = offer price, images downloaded best-effort, and
 * "Group:Value|..." variant attributes → combination (attribute groups / values created when missing).
 * Rows sharing a parent_reference become combinations of the same product.
 */
final class ProductFactory
{
    /** @var array<string,int> */
    private array $manufacturers = [];
    /** @var array<string,int> */
    private array $categories = [];
    /** @var array<string,int> */
    private array $groups = [];
    /** @var array<string,int> */
    private array $attributes = [];
    private int $idLang;
    private int $idShop;

    public function __construct(?int $idLang = null, ?int $idShop = null)
    {
        $this->idLang = $idLang ?: (int) \Configuration::get('PS_LANG_DEFAULT');
        $this->idShop = $idShop ?: (int) \Context::getContext()->shop->id;
    }

    /**
     * @param array<string,mixed> $draft OfferRowMapper::toProductDraft()
     * @param string[] $warnings collected non-blocking problems (images, category...)
     *
     * @return array{id_product:int, id_product_attribute:int, created_product:bool}
     */
    public function create(array $draft, array &$warnings = []): array
    {
        if (trim((string) $draft['name']) === '') {
            throw new \InvalidArgumentException('title: required to create a new master product');
        }
        $variants = (array) ($draft['variant_attributes'] ?? []);
        if ($variants !== [] && !empty($draft['parent_reference'])) {
            $idParent = $this->findProductByReference((string) $draft['parent_reference']);
            if ($idParent) {
                $idAttr = $this->addCombination($idParent, $draft, $warnings);

                return ['id_product' => $idParent, 'id_product_attribute' => $idAttr, 'created_product' => false];
            }
        }

        $idCategory = $this->categoryId($draft['category'] ?? null, $warnings);
        $p = new \Product(null, false, null, $this->idShop);
        $name = (string) $draft['name'];
        $p->name = $this->lang($name);
        $p->link_rewrite = $this->lang(\Tools::str2url($name) ?: 'product');
        $p->description = $this->lang((string) ($draft['description'] ?? ''));
        $p->description_short = $this->lang((string) ($draft['description_short'] ?? ''));
        $p->ean13 = $variants === [] ? (string) ($draft['ean13'] ?? '') : '';
        $p->reference = (string) ($draft['reference'] ?? '');
        $p->mpn = $variants === [] ? (string) ($draft['mpn'] ?? '') : '';
        $p->id_manufacturer = $this->manufacturerId($draft['brand'] ?? null);
        $p->price = round((float) $draft['price'], 6);
        $p->wholesale_price = 0;
        $p->id_tax_rules_group = Config::int('BXMPCSV_TAX_RULES_GROUP');
        $p->id_category_default = $idCategory;
        $p->condition = in_array($draft['condition'] ?? 'new', ['new', 'used', 'refurbished'], true) ? $draft['condition'] : 'new';
        $p->active = Config::bool('BXMPCSV_PRODUCT_ACTIVE');
        $p->visibility = 'both';
        $p->available_for_order = true;
        $p->show_price = true;
        $p->state = \Product::STATE_SAVED;
        $p->product_type = $variants === [] ? ProductType::TYPE_STANDARD : ProductType::TYPE_COMBINATIONS;
        $errors = $p->validateFields(false, true);
        if ($errors === true) {
            $errors = $p->validateFieldsLang(false, true);
        }
        if ($errors !== true) {
            throw new \InvalidArgumentException('product: ' . strip_tags((string) $errors));
        }
        if (!$p->add()) {
            throw new \RuntimeException('product: could not be created');
        }
        $p->updateCategories(array_unique([$idCategory]));

        $idAttr = 0;
        if ($variants !== []) {
            $idAttr = $this->addCombination((int) $p->id, $draft, $warnings, $p);
        }
        if (Config::bool('BXMPCSV_IMPORT_IMAGES') && !empty($draft['images'])) {
            $this->importImages((int) $p->id, (array) $draft['images'], $name, $warnings, $idAttr);
        }

        return ['id_product' => (int) $p->id, 'id_product_attribute' => $idAttr, 'created_product' => true];
    }

    /** Combination with the draft's variant attributes on an existing product (re-used when the set exists). */
    public function addCombination(int $idProduct, array $draft, array &$warnings = [], ?\Product $product = null): int
    {
        $attrIds = [];
        foreach ((array) $draft['variant_attributes'] as $group => $value) {
            $attrIds[] = $this->attributeId((string) $group, (string) $value);
        }
        $attrIds = array_values(array_unique($attrIds));
        $ean = (string) ($draft['ean13'] ?? '');
        $existing = $this->findCombination($idProduct, $attrIds);
        if ($existing) {
            if ($ean !== '') {
                \Db::getInstance()->update('product_attribute', ['ean13' => pSQL($ean)], 'id_product_attribute = ' . $existing . " AND (ean13 IS NULL OR ean13 = '')");
            }
            $warnings[] = 'variant: combination already existed on product #' . $idProduct;

            return $existing;
        }
        $product ??= new \Product($idProduct, false, null, $this->idShop);
        $hasDefault = (int) \Product::getDefaultAttribute($idProduct) > 0;
        $impact = round((float) $draft['price'] - (float) $product->price, 6);
        $ref = (string) ($draft['combination_reference'] ?? '');
        $mpn = $ref !== '' && mb_strlen($ref) <= 40 ? $ref : '';
        $idPa = (int) $product->addCombinationEntity(0, $impact, 0, 0, 0, 0, [], $ref, 0, $ean, !$hasDefault, null, null, 1, [], null, '', null, false, $mpn);
        if (!$idPa) {
            throw new \RuntimeException('variant: combination could not be created');
        }
        (new \Combination($idPa))->setAttributes($attrIds);
        $product->product_type = ProductType::TYPE_COMBINATIONS;
        $product->setDefaultAttribute((int) \Product::getDefaultAttribute($idProduct, 0, true) ?: $idPa);

        return $idPa;
    }

    public function findProductByReference(string $reference): int
    {
        return (int) \Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . "product` WHERE reference = '" . pSQL($reference) . "' ORDER BY id_product");
    }

    /** @param int[] $attrIds */
    private function findCombination(int $idProduct, array $attrIds): int
    {
        if ($attrIds === []) {
            return 0;
        }
        $n = count($attrIds);

        return (int) \Db::getInstance()->getValue('SELECT pac.id_product_attribute
            FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.id_product_attribute = pac.id_product_attribute
            WHERE pa.id_product = ' . $idProduct . '
            GROUP BY pac.id_product_attribute
            HAVING COUNT(*) = ' . $n . ' AND SUM(pac.id_attribute IN (' . implode(',', array_map('intval', $attrIds)) . ')) = ' . $n);
    }

    public function manufacturerId(?string $brand): int
    {
        $brand = trim((string) $brand);
        if ($brand === '') {
            return 0;
        }
        $key = mb_strtolower($brand);
        if (isset($this->manufacturers[$key])) {
            return $this->manufacturers[$key];
        }
        $id = (int) \Db::getInstance()->getValue('SELECT id_manufacturer FROM `' . _DB_PREFIX_ . "manufacturer` WHERE LOWER(name) = '" . pSQL($key) . "'");
        if (!$id) {
            $m = new \Manufacturer();
            $m->name = $brand;
            $m->active = true;
            if (!$m->add()) {
                throw new \RuntimeException('brand: manufacturer "' . $brand . '" could not be created');
            }
            $id = (int) $m->id;
        }

        return $this->manufacturers[$key] = $id;
    }

    /** Category id for "id" / "A > B > C" (created under Home when allowed); default category otherwise. */
    public function categoryId(?string $category, array &$warnings = []): int
    {
        $default = Config::int('BXMPCSV_ID_CATEGORY') ?: (int) \Configuration::get('PS_HOME_CATEGORY');
        $category = trim((string) $category);
        if ($category === '') {
            return $default;
        }
        if (isset($this->categories[$category])) {
            return $this->categories[$category];
        }
        if (ctype_digit($category)) {
            if (\Category::categoryExists((int) $category)) {
                return $this->categories[$category] = (int) $category;
            }
            $warnings[] = 'category: id ' . $category . ' does not exist, default category used';

            return $default;
        }
        $parent = (int) \Configuration::get('PS_HOME_CATEGORY');
        foreach (OfferRowMapper::categoryPath($category) as $name) {
            $id = (int) \Db::getInstance()->getValue('SELECT c.id_category FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $this->idLang . ' AND cl.id_shop = ' . $this->idShop . '
                WHERE c.id_parent = ' . $parent . " AND LOWER(cl.name) = '" . pSQL(mb_strtolower($name)) . "'");
            if (!$id) {
                if (!Config::bool('BXMPCSV_CREATE_CATEGORIES')) {
                    $warnings[] = 'category: "' . $category . '" not found, default category used';

                    return $this->categories[$category] = $default;
                }
                $clean = trim(preg_replace('/[<>;=#{}]/u', ' ', $name) ?? '');
                $c = new \Category();
                $c->name = $this->lang($clean);
                $c->link_rewrite = $this->lang(\Tools::str2url($clean) ?: 'category');
                $c->id_parent = $parent;
                $c->active = true;
                if (!$c->add()) {
                    $warnings[] = 'category: "' . $name . '" could not be created, default category used';

                    return $this->categories[$category] = $default;
                }
                $id = (int) $c->id;
            }
            $parent = $id;
        }

        return $this->categories[$category] = $parent;
    }

    public function attributeId(string $group, string $value): int
    {
        $gKey = mb_strtolower($group);
        if (!isset($this->groups[$gKey])) {
            $idGroup = (int) \Db::getInstance()->getValue('SELECT id_attribute_group FROM `' . _DB_PREFIX_ . 'attribute_group_lang`
                WHERE id_lang = ' . $this->idLang . " AND (LOWER(name) = '" . pSQL($gKey) . "' OR LOWER(public_name) = '" . pSQL($gKey) . "') ORDER BY id_attribute_group");
            if (!$idGroup) {
                $g = new \AttributeGroup();
                $g->name = $this->lang($group);
                $g->public_name = $this->lang($group);
                $g->group_type = in_array($gKey, ['color', 'colour', 'colore', 'couleur', 'farbe'], true) ? 'color' : 'select';
                if (!$g->add()) {
                    throw new \RuntimeException('variant: attribute group "' . $group . '" could not be created');
                }
                $idGroup = (int) $g->id;
            }
            $this->groups[$gKey] = $idGroup;
        }
        $idGroup = $this->groups[$gKey];
        $aKey = $idGroup . ':' . mb_strtolower($value);
        if (!isset($this->attributes[$aKey])) {
            $id = (int) \Db::getInstance()->getValue('SELECT a.id_attribute FROM `' . _DB_PREFIX_ . 'attribute` a
                INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $this->idLang . '
                WHERE a.id_attribute_group = ' . $idGroup . " AND LOWER(al.name) = '" . pSQL(mb_strtolower($value)) . "'");
            if (!$id) {
                $a = new \ProductAttribute();
                $a->id_attribute_group = $idGroup;
                $a->name = $this->lang($value);
                $a->color = '';
                if (!$a->add()) {
                    throw new \RuntimeException('variant: attribute "' . $group . ':' . $value . '" could not be created');
                }
                $id = (int) $a->id;
            }
            $this->attributes[$aKey] = $id;
        }

        return $this->attributes[$aKey];
    }

    /**
     * Download images best-effort (each failure is a warning, never an error).
     *
     * @param string[] $urls
     */
    public function importImages(int $idProduct, array $urls, string $legend, array &$warnings = [], int $idProductAttribute = 0): int
    {
        $ok = 0;
        $imageIds = [];
        foreach ($urls as $url) {
            try {
                $image = new \Image();
                $image->id_product = $idProduct;
                $image->position = \Image::getHighestPosition($idProduct) + 1;
                $image->cover = !\Image::getCover($idProduct);
                $image->legend = $this->lang(mb_substr(trim(preg_replace('/[<>;=#{}]/u', ' ', $legend) ?? ''), 0, 128));
                if (!$image->add()) {
                    $warnings[] = 'images: could not create image record';
                    continue;
                }
                if (!\ImageManager::copyImg($idProduct, (int) $image->id, $url, 'products', true)) {
                    $image->delete();
                    $warnings[] = 'images: download failed for ' . mb_substr($url, 0, 120);
                    continue;
                }
                $imageIds[] = (int) $image->id;
                ++$ok;
            } catch (\Throwable $e) {
                $warnings[] = 'images: ' . mb_substr($url, 0, 120) . ' — ' . $e->getMessage();
            }
        }
        if ($idProductAttribute && $imageIds !== []) {
            (new \Combination($idProductAttribute))->setImages($imageIds);
        }

        return $ok;
    }

    /** @return array<int,string> */
    private function lang(string $value): array
    {
        $out = [];
        foreach (\Language::getIDs(false) as $id) {
            $out[(int) $id] = $value;
        }

        return $out;
    }
}

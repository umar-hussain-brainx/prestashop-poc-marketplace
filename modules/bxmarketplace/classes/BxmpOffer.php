<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpOffer extends ObjectModel
{
    public $id_vendor;
    public $id_product;
    public $id_product_attribute = 0;
    public $vendor_sku;
    public $ean13;
    /** @var float tax excluded */
    public $price = 0;
    /** @var float|null tax excluded */
    public $discount_price;
    public $discount_from;
    public $discount_to;
    public $quantity = 0;
    public $condition = 'new';
    public $active = true;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'bxmp_offer',
        'primary' => 'id_offer',
        'fields' => [
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_product' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_product_attribute' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'vendor_sku' => ['type' => self::TYPE_STRING, 'validate' => 'isReference', 'size' => 64, 'allow_null' => true],
            'ean13' => ['type' => self::TYPE_STRING, 'validate' => 'isEan13', 'size' => 13, 'allow_null' => true],
            'price' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'required' => true],
            'discount_price' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'allow_null' => true],
            'discount_from' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'discount_to' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'quantity' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'condition' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'values' => ['new', 'used', 'refurbished'], 'default' => 'new'],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public static function findId(int $idVendor, int $idProduct, int $idProductAttribute = 0): int
    {
        return (int) Db::getInstance()->getValue('SELECT id_offer FROM ' . _DB_PREFIX_ . 'bxmp_offer WHERE id_vendor = ' . (int) $idVendor
            . ' AND id_product = ' . (int) $idProduct . ' AND id_product_attribute = ' . (int) $idProductAttribute);
    }

    /** Active offers of active vendors for one product/combination. */
    public static function getForProduct(int $idProduct, ?int $idProductAttribute = null, bool $activeOnly = true): array
    {
        $sql = 'SELECT o.*, v.name AS vendor_name, v.code AS vendor_code FROM ' . _DB_PREFIX_ . 'bxmp_offer o
            INNER JOIN ' . _DB_PREFIX_ . 'bxmp_vendor v ON v.id_vendor = o.id_vendor
            WHERE o.id_product = ' . (int) $idProduct
            . ($idProductAttribute !== null ? ' AND o.id_product_attribute = ' . (int) $idProductAttribute : '')
            . ($activeOnly ? ' AND o.active = 1 AND v.active = 1' : '')
            . ' ORDER BY o.price ASC';

        return Db::getInstance()->executeS($sql) ?: [];
    }
}

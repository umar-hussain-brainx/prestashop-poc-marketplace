<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpCartAllocation extends ObjectModel
{
    public $id_cart;
    public $id_product;
    public $id_product_attribute = 0;
    public $id_offer;
    public $id_vendor;
    public $quantity = 0;
    public $unit_price = 0;
    public $date_add;

    public static $definition = [
        'table' => 'bxmp_cart_allocation',
        'primary' => 'id_cart_allocation',
        'fields' => [
            'id_cart' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_product' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_product_attribute' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'id_offer' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'quantity' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'unit_price' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}

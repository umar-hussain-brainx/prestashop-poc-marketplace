<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpVendorOrderLine extends ObjectModel
{
    public $id_vendor_order;
    public $id_offer = 0;
    public $id_product;
    public $id_product_attribute = 0;
    public $product_name;
    public $vendor_sku;
    public $quantity = 0;
    public $unit_price = 0;
    public $commission_amount = 0;

    public static $definition = [
        'table' => 'bxmp_vendor_order_line',
        'primary' => 'id_vendor_order_line',
        'fields' => [
            'id_vendor_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_offer' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'id_product' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_product_attribute' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'product_name' => ['type' => self::TYPE_STRING, 'size' => 255, 'allow_null' => true],
            'vendor_sku' => ['type' => self::TYPE_STRING, 'size' => 64, 'allow_null' => true],
            'quantity' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'unit_price' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice'],
            'commission_amount' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
        ],
    ];
}

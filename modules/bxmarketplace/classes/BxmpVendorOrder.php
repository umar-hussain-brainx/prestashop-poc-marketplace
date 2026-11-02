<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpVendorOrder extends ObjectModel
{
    public const STATUSES = ['pending', 'accepted', 'shipped', 'delivered', 'cancelled'];

    public $id_order;
    public $id_vendor;
    public $reference;
    public $total_products = 0;
    public $total_shipping = 0;
    public $total_paid = 0;
    public $commission_amount = 0;
    public $vendor_payout = 0;
    /** @var string pending|accepted|shipped|delivered|cancelled */
    public $status = 'pending';
    public $tracking_number;
    public $carrier_name;
    public $payment_transfer_ref;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'bxmp_vendor_order',
        'primary' => 'id_vendor_order',
        'fields' => [
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'reference' => ['type' => self::TYPE_STRING, 'size' => 64, 'allow_null' => true],
            'total_products' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice'],
            'total_shipping' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice'],
            'total_paid' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice'],
            'commission_amount' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'vendor_payout' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'status' => ['type' => self::TYPE_STRING, 'values' => self::STATUSES, 'default' => 'pending'],
            'tracking_number' => ['type' => self::TYPE_STRING, 'size' => 128, 'allow_null' => true],
            'carrier_name' => ['type' => self::TYPE_STRING, 'size' => 128, 'allow_null' => true],
            'payment_transfer_ref' => ['type' => self::TYPE_STRING, 'size' => 128, 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /** @return array<int, array<string, mixed>> */
    public function getLines(): array
    {
        return Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'bxmp_vendor_order_line WHERE id_vendor_order = ' . (int) $this->id) ?: [];
    }

    /** @return int[] */
    public static function getIdsByOrder(int $idOrder): array
    {
        return array_map('intval', array_column(Db::getInstance()->executeS('SELECT id_vendor_order FROM ' . _DB_PREFIX_ . 'bxmp_vendor_order WHERE id_order = ' . (int) $idOrder) ?: [], 'id_vendor_order'));
    }
}

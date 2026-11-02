<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpCommissionAdjustment extends ObjectModel
{
    public $id_vendor;
    public $id_vendor_order;
    /** @var float positive = credit to the vendor, negative = deduction */
    public $amount = 0;
    public $reason;
    public $date_add;

    public static $definition = [
        'table' => 'bxmp_commission_adjustment',
        'primary' => 'id_adjustment',
        'fields' => [
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_vendor_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'allow_null' => true],
            'amount' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'reason' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 255, 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}

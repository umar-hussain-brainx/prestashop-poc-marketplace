<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpSettlement extends ObjectModel
{
    public $id_vendor;
    public $period_from;
    public $period_to;
    public $gross = 0;
    public $commission = 0;
    public $adjustments = 0;
    public $net_payout = 0;
    /** @var string draft|approved|paid */
    public $status = 'draft';
    public $date_add;

    public static $definition = [
        'table' => 'bxmp_settlement',
        'primary' => 'id_settlement',
        'fields' => [
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'period_from' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'required' => true],
            'period_to' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'required' => true],
            'gross' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'commission' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'adjustments' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'net_payout' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'status' => ['type' => self::TYPE_STRING, 'values' => ['draft', 'approved', 'paid'], 'default' => 'draft'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}

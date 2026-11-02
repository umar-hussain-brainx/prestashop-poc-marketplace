<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpShippingRule extends ObjectModel
{
    public $id_vendor;
    /** @var int 0 = all countries */
    public $id_country = 0;
    /** @var string|null comma list of prefixes / ranges, e.g. "20,21,00100-00199"; null = all */
    public $zipcode_pattern;
    /** @var bool 1 = vendor does NOT deliver to the matched destination */
    public $excluded = false;
    public $base_cost = 0;
    public $free_threshold;
    public $reduced_threshold;
    public $reduced_cost;
    /** @var int higher wins */
    public $priority = 0;

    public static $definition = [
        'table' => 'bxmp_shipping_rule',
        'primary' => 'id_shipping_rule',
        'fields' => [
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_country' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'zipcode_pattern' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 255, 'allow_null' => true],
            'excluded' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'base_cost' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice'],
            'free_threshold' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'allow_null' => true],
            'reduced_threshold' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'allow_null' => true],
            'reduced_cost' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'allow_null' => true],
            'priority' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
        ],
    ];

    public static function getForVendor(int $idVendor): array
    {
        return Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'bxmp_shipping_rule WHERE id_vendor = ' . (int) $idVendor . ' ORDER BY priority DESC, id_shipping_rule') ?: [];
    }
}

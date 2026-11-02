<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpFeed extends ObjectModel
{
    public $id_vendor;
    public $url_or_path;
    public $schedule_minutes = 60;
    public $last_run;
    public $active = true;

    public static $definition = [
        'table' => 'bxmp_feed',
        'primary' => 'id_feed',
        'fields' => [
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'url_or_path' => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 512],
            'schedule_minutes' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'last_run' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
        ],
    ];
}

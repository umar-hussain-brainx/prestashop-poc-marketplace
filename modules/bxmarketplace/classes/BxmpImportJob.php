<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpImportJob extends ObjectModel
{
    public $id_vendor;
    /** @var string offers|order_status */
    public $type = 'offers';
    /** @var string upload|url|path */
    public $source = 'upload';
    public $file_path;
    /** @var string queued|running|done|failed */
    public $status = 'queued';
    public $rows_total = 0;
    public $rows_ok = 0;
    public $rows_error = 0;
    public $log;
    public $date_add;
    public $date_start;
    public $date_end;

    public static $definition = [
        'table' => 'bxmp_import_job',
        'primary' => 'id_import_job',
        'fields' => [
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'type' => ['type' => self::TYPE_STRING, 'values' => ['offers', 'order_status'], 'default' => 'offers'],
            'source' => ['type' => self::TYPE_STRING, 'values' => ['upload', 'url', 'path'], 'default' => 'upload'],
            'file_path' => ['type' => self::TYPE_STRING, 'size' => 512, 'allow_null' => true],
            'status' => ['type' => self::TYPE_STRING, 'values' => ['queued', 'running', 'done', 'failed'], 'default' => 'queued'],
            'rows_total' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'rows_ok' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'rows_error' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'log' => ['type' => self::TYPE_STRING, 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_start' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'date_end' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
        ],
    ];
}

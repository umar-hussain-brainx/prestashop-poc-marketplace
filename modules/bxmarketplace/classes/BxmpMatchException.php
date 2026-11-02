<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpMatchException extends ObjectModel
{
    public $id_vendor;
    public $id_import_job;
    public $vendor_sku;
    public $ean13;
    public $brand;
    public $title;
    /** @var string JSON of the original vendor row */
    public $payload;
    public $reason;
    /** @var string JSON list of candidate id_product */
    public $candidates;
    /** @var string pending|resolved|rejected */
    public $status = 'pending';
    public $id_product_resolved;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'bxmp_match_exception',
        'primary' => 'id_match_exception',
        'fields' => [
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_import_job' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'allow_null' => true],
            'vendor_sku' => ['type' => self::TYPE_STRING, 'size' => 64, 'allow_null' => true],
            'ean13' => ['type' => self::TYPE_STRING, 'size' => 32, 'allow_null' => true],
            'brand' => ['type' => self::TYPE_STRING, 'size' => 128, 'allow_null' => true],
            'title' => ['type' => self::TYPE_STRING, 'size' => 255, 'allow_null' => true],
            'payload' => ['type' => self::TYPE_STRING, 'allow_null' => true],
            'reason' => ['type' => self::TYPE_STRING, 'size' => 64, 'allow_null' => true],
            'candidates' => ['type' => self::TYPE_STRING, 'allow_null' => true],
            'status' => ['type' => self::TYPE_STRING, 'values' => ['pending', 'resolved', 'rejected'], 'default' => 'pending'],
            'id_product_resolved' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}

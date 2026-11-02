<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpCollection extends ObjectModel
{
    public $name;
    public $slug;
    public $description;
    public $active = true;
    public $date_start;
    public $date_end;
    public $position = 0;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'bxmp_collection',
        'primary' => 'id_collection',
        'fields' => [
            'name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 128],
            'slug' => ['type' => self::TYPE_STRING, 'validate' => 'isLinkRewrite', 'required' => true, 'size' => 128],
            'description' => ['type' => self::TYPE_HTML, 'validate' => 'isCleanHtml', 'allow_null' => true],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'date_start' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'date_end' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'position' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /** Active and inside its [date_start, date_end] window (null bounds = open). */
    public function isLive(?string $now = null): bool
    {
        $now = $now ?: date('Y-m-d H:i:s');
        if (!$this->active) {
            return false;
        }
        if ($this->date_start && $this->date_start !== '0000-00-00 00:00:00' && $now < $this->date_start) {
            return false;
        }
        if ($this->date_end && $this->date_end !== '0000-00-00 00:00:00' && $now > $this->date_end) {
            return false;
        }

        return true;
    }

    /** @return int[] explicit product ids (bxmp_collection_product) */
    public function getProductIds(): array
    {
        return array_map('intval', array_column(Db::getInstance()->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'bxmp_collection_product WHERE id_collection = ' . (int) $this->id . ' ORDER BY position') ?: [], 'id_product'));
    }

    /** @return int[] category ids (bxmp_collection_category) */
    public function getCategoryIds(): array
    {
        return array_map('intval', array_column(Db::getInstance()->executeS('SELECT id_category FROM ' . _DB_PREFIX_ . 'bxmp_collection_category WHERE id_collection = ' . (int) $this->id) ?: [], 'id_category'));
    }
}

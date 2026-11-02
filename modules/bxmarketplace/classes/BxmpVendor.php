<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpVendor extends ObjectModel
{
    /** @var string */
    public $name;
    /** @var string unique slug */
    public $code;
    public $email;
    public $company;
    public $vat_number;
    /** @var bool */
    public $active = true;
    /** @var float percent, e.g. 12.5 */
    public $commission_rate = 0;
    /** @var float EUR per order line */
    public $commission_fixed = 0;
    /** @var string Mangopay wallet id / IBAN reference */
    public $payout_account;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'bxmp_vendor',
        'primary' => 'id_vendor',
        'fields' => [
            'name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 128],
            'code' => ['type' => self::TYPE_STRING, 'validate' => 'isLinkRewrite', 'required' => true, 'size' => 64],
            'email' => ['type' => self::TYPE_STRING, 'validate' => 'isEmail', 'size' => 255, 'allow_null' => true],
            'company' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 255, 'allow_null' => true],
            'vat_number' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32, 'allow_null' => true],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'commission_rate' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPercentage'],
            'commission_fixed' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice'],
            'payout_account' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 255, 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /** Cascades to vendor users, offers, shipping rules, feeds and API tokens (orders/settlements are kept). */
    public function delete()
    {
        $id = (int) $this->id;
        $ok = parent::delete();
        if ($ok && $id) {
            foreach (['bxmp_vendor_user', 'bxmp_offer', 'bxmp_shipping_rule', 'bxmp_feed', 'bxmp_api_token'] as $table) {
                Db::getInstance()->delete($table, 'id_vendor = ' . $id);
            }
        }

        return $ok;
    }

    public static function getIdByCode(string $code): int
    {
        return (int) Db::getInstance()->getValue('SELECT id_vendor FROM ' . _DB_PREFIX_ . 'bxmp_vendor WHERE code = \'' . pSQL($code) . '\'');
    }

    /** @return array<int, array<string, mixed>> */
    public static function getAll(bool $activeOnly = false): array
    {
        return Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'bxmp_vendor' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name') ?: [];
    }

    /** id => name map, handy for selects */
    public static function getNames(bool $activeOnly = false): array
    {
        $out = [];
        foreach (self::getAll($activeOnly) as $row) {
            $out[(int) $row['id_vendor']] = $row['name'];
        }

        return $out;
    }
}

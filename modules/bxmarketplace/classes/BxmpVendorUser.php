<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpVendorUser extends ObjectModel
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_STAFF = 'staff';
    public const ROLES = [self::ROLE_OWNER, self::ROLE_MANAGER, self::ROLE_STAFF];

    public $id_vendor;
    public $id_customer;
    /** @var string owner|manager|staff */
    public $role = self::ROLE_STAFF;
    public $active = true;

    public static $definition = [
        'table' => 'bxmp_vendor_user',
        'primary' => 'id_vendor_user',
        'fields' => [
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_customer' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'role' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'values' => self::ROLES, 'default' => 'staff'],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
        ],
    ];

    /**
     * Active vendor membership of a PS customer (first active link), or null.
     *
     * @return array{id_vendor_user:int,id_vendor:int,id_customer:int,role:string,vendor_name:string}|null
     */
    public static function getMembershipForCustomer(int $idCustomer): ?array
    {
        $row = Db::getInstance()->getRow('SELECT vu.id_vendor_user, vu.id_vendor, vu.id_customer, vu.role, v.name AS vendor_name
            FROM ' . _DB_PREFIX_ . 'bxmp_vendor_user vu
            INNER JOIN ' . _DB_PREFIX_ . 'bxmp_vendor v ON v.id_vendor = vu.id_vendor AND v.active = 1
            WHERE vu.active = 1 AND vu.id_customer = ' . (int) $idCustomer . '
            ORDER BY FIELD(vu.role, \'owner\', \'manager\', \'staff\')');
        if (!$row) {
            return null;
        }

        return [
            'id_vendor_user' => (int) $row['id_vendor_user'],
            'id_vendor' => (int) $row['id_vendor'],
            'id_customer' => (int) $row['id_customer'],
            'role' => (string) $row['role'],
            'vendor_name' => (string) $row['vendor_name'],
        ];
    }

    public static function findId(int $idVendor, int $idCustomer): int
    {
        return (int) Db::getInstance()->getValue('SELECT id_vendor_user FROM ' . _DB_PREFIX_ . 'bxmp_vendor_user WHERE id_vendor = ' . (int) $idVendor . ' AND id_customer = ' . (int) $idCustomer);
    }

    /** Role hierarchy check: owner > manager > staff. */
    public static function roleAtLeast(string $role, string $required): bool
    {
        $rank = [self::ROLE_STAFF => 1, self::ROLE_MANAGER => 2, self::ROLE_OWNER => 3];

        return ($rank[$role] ?? 0) >= ($rank[$required] ?? 99);
    }
}

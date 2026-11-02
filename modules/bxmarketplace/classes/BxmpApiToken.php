<?php
/**
 * BrainX Marketplace core ObjectModel (global namespace so other modules and HelperList/HelperForm can use it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class BxmpApiToken extends ObjectModel
{
    /** @var string 64 hex chars */
    public $token;
    public $label;
    /** @var int|null null = admin scope */
    public $id_vendor;
    public $active = true;
    public $date_add;

    public static $definition = [
        'table' => 'bxmp_api_token',
        'primary' => 'id_api_token',
        'fields' => [
            'token' => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 64],
            'label' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 128, 'allow_null' => true],
            'id_vendor' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'allow_null' => true],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Creates and saves a new token; returns the ObjectModel (token in ->token). */
    public static function issue(string $label, ?int $idVendor = null): self
    {
        $t = new self();
        $t->token = self::generateToken();
        $t->label = $label;
        $t->id_vendor = $idVendor ?: null;
        $t->active = true;
        $t->add();

        return $t;
    }

    public function isAdminScope(): bool
    {
        return empty($this->id_vendor);
    }
}

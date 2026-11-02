<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Repository;

final class ApiTokenRepository
{
    /** Active token row (with vendor active flag) or null. Constant-time compare on the fetched value. */
    public function findActive(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = \Db::getInstance()->getRow('SELECT t.*, v.active AS vendor_active FROM `' . _DB_PREFIX_ . 'bxmp_api_token` t
            LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = t.id_vendor
            WHERE t.active = 1 AND t.token = \'' . pSQL($token) . '\'');
        if (!$row || !hash_equals((string) $row['token'], $token)) {
            return null;
        }
        if (!empty($row['id_vendor']) && (int) $row['vendor_active'] !== 1) {
            return null;
        }

        return $row;
    }

    public function all(): array
    {
        return \Db::getInstance()->executeS('SELECT t.*, v.name AS vendor_name FROM `' . _DB_PREFIX_ . 'bxmp_api_token` t
            LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = t.id_vendor ORDER BY t.id_api_token DESC') ?: [];
    }

    public function findByLabel(string $label): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_api_token` WHERE label = \'' . pSQL($label) . '\' AND active = 1 ORDER BY id_api_token');

        return $row ?: null;
    }

    public function issue(string $label, ?int $idVendor = null): string
    {
        return \BxmpApiToken::issue($label, $idVendor)->token;
    }

    public function revoke(int $idApiToken): bool
    {
        return \Db::getInstance()->update('bxmp_api_token', ['active' => 0], 'id_api_token = ' . (int) $idApiToken);
    }
}

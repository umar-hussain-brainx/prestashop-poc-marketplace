<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Repository;

use BrainX\Marketplace\Domain\Commission\CommissionTerms;

/** Read access to bxmp_vendor / bxmp_vendor_user. Writes go through the BxmpVendor ObjectModel. */
final class VendorRepository
{
    private function db(): \Db
    {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_);
    }

    public function findAll(bool $activeOnly = false): array
    {
        return $this->db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_vendor`' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name') ?: [];
    }

    public function find(int $idVendor): ?array
    {
        $row = $this->db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_vendor` WHERE id_vendor = ' . (int) $idVendor);

        return $row ?: null;
    }

    /** @param int[] $ids @return array<int, array> id => row */
    public function findByIds(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_vendor` WHERE id_vendor IN (' . implode(',', $ids) . ')') ?: [] as $row) {
            $out[(int) $row['id_vendor']] = $row;
        }

        return $out;
    }

    public function commissionTerms(int $idVendor, bool $applyOnShipping = false): CommissionTerms
    {
        return CommissionTerms::fromVendorRow($this->find($idVendor) ?? [], $applyOnShipping);
    }

    /** Vendor users with customer identity. */
    public function users(int $idVendor): array
    {
        return $this->db()->executeS('SELECT vu.*, c.email, c.firstname, c.lastname FROM `' . _DB_PREFIX_ . 'bxmp_vendor_user` vu
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = vu.id_customer
            WHERE vu.id_vendor = ' . (int) $idVendor . ' ORDER BY vu.role, c.email') ?: [];
    }

    /** @see \BxmpVendorUser::getMembershipForCustomer() */
    public function membershipForCustomer(int $idCustomer): ?array
    {
        return \BxmpVendorUser::getMembershipForCustomer($idCustomer);
    }
}

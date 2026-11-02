<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Repository;

final class VendorOrderRepository
{
    /**
     * @param array{id_vendor?:int,id_order?:int,status?:string,date_from?:string,date_to?:string} $filters
     */
    public function search(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = $this->where($filters);

        return \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT vo.*, v.name AS vendor_name
            FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` vo
            INNER JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = vo.id_vendor
            WHERE ' . $where . ' ORDER BY vo.date_add DESC, vo.id_vendor_order DESC
            LIMIT ' . max(0, $offset) . ', ' . max(1, min(1000, $limit))) ?: [];
    }

    public function count(array $filters = []): int
    {
        return (int) \Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` vo WHERE ' . $this->where($filters));
    }

    public function find(int $idVendorOrder): ?array
    {
        $row = \Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow('SELECT vo.*, v.name AS vendor_name FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` vo
            INNER JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = vo.id_vendor WHERE vo.id_vendor_order = ' . (int) $idVendorOrder);
        if (!$row) {
            return null;
        }
        $row['lines'] = $this->lines((int) $row['id_vendor_order']);

        return $row;
    }

    public function lines(int $idVendorOrder): array
    {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order_line` WHERE id_vendor_order = ' . (int) $idVendorOrder) ?: [];
    }

    private function where(array $f): string
    {
        $w = ['1'];
        if (isset($f['id_vendor'])) {
            $w[] = 'vo.id_vendor = ' . (int) $f['id_vendor'];
        }
        if (isset($f['id_order'])) {
            $w[] = 'vo.id_order = ' . (int) $f['id_order'];
        }
        if (!empty($f['status']) && in_array($f['status'], \BxmpVendorOrder::STATUSES, true)) {
            $w[] = 'vo.status = \'' . pSQL($f['status']) . '\'';
        }
        if (!empty($f['date_from'])) {
            $w[] = 'vo.date_add >= \'' . pSQL($f['date_from']) . '\'';
        }
        if (!empty($f['date_to'])) {
            $w[] = 'vo.date_add <= \'' . pSQL($f['date_to']) . ' 23:59:59\'';
        }

        return implode(' AND ', $w);
    }
}

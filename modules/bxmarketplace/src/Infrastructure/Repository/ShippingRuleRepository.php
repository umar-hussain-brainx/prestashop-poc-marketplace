<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Repository;

use BrainX\Marketplace\Domain\Optimizer\ShippingPolicy;
use BrainX\Marketplace\Domain\Shipping\ShippingRule;

final class ShippingRuleRepository
{
    public function findByVendor(int $idVendor): array
    {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_shipping_rule` WHERE id_vendor = ' . (int) $idVendor . ' ORDER BY priority DESC, id_shipping_rule') ?: [];
    }

    /**
     * Domain shipping policies for the given vendors (all vendors when $vendorIds is null).
     *
     * @param int[]|null $vendorIds
     *
     * @return array<int, ShippingPolicy> idVendor => policy
     */
    public function policiesFor(?array $vendorIds = null): array
    {
        $where = '';
        if ($vendorIds !== null) {
            $ids = array_filter(array_map('intval', $vendorIds));
            if ($ids === []) {
                return [];
            }
            $where = ' WHERE v.id_vendor IN (' . implode(',', $ids) . ')';
        }
        $db = \Db::getInstance(_PS_USE_SQL_SLAVE_);
        $vendors = $db->executeS('SELECT v.id_vendor, v.name FROM `' . _DB_PREFIX_ . 'bxmp_vendor` v' . $where) ?: [];
        $rules = [];
        if ($vendors !== []) {
            $ids = array_map(static fn ($v) => (int) $v['id_vendor'], $vendors);
            foreach ($db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_shipping_rule` WHERE id_vendor IN (' . implode(',', $ids) . ')') ?: [] as $row) {
                $rules[(int) $row['id_vendor']][] = ShippingRule::fromArray($row);
            }
        }
        $out = [];
        foreach ($vendors as $v) {
            $id = (int) $v['id_vendor'];
            $out[$id] = new ShippingPolicy($id, $rules[$id] ?? [], (string) $v['name']);
        }

        return $out;
    }
}

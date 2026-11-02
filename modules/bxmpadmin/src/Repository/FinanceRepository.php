<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Repository;

use BrainX\MarketplaceAdmin\Domain\Period;

/**
 * Read model for commissions, adjustments and settlements.
 *
 * A vendor order is "settled" (locked) when its date falls inside an approved or paid settlement of its vendor;
 * locked orders are never recalculated and locked adjustments cannot be edited.
 */
final class FinanceRepository
{
    public const LOCKED_STATUSES = ['approved', 'paid'];

    private \Db $db;

    public function __construct(?\Db $db = null)
    {
        $this->db = $db ?? \Db::getInstance();
    }

    private static function t(string $table): string
    {
        return '`' . _DB_PREFIX_ . $table . '`';
    }

    /**
     * @param array{id_vendor?:int|null,status?:string|null,unsettled?:bool,only_ids?:int[]} $f
     */
    public function vendorOrders(?Period $p, array $f = [], int $limit = 500, int $offset = 0): array
    {
        $w = ['1'];
        if ($p) {
            $w[] = "vo.date_add BETWEEN '" . pSQL($p->fromSql()) . "' AND '" . pSQL($p->toSql()) . "'";
        }
        if (!empty($f['id_vendor'])) {
            $w[] = 'vo.id_vendor = ' . (int) $f['id_vendor'];
        }
        if (!empty($f['status']) && in_array($f['status'], \BxmpVendorOrder::STATUSES, true)) {
            $w[] = "vo.status = '" . pSQL($f['status']) . "'";
        }
        if (!empty($f['only_ids'])) {
            $w[] = 'vo.id_vendor_order IN (' . implode(',', array_map('intval', $f['only_ids'])) . ')';
        }
        $settled = '(SELECT s.id_settlement FROM ' . self::t('bxmp_settlement') . " s WHERE s.id_vendor = vo.id_vendor
            AND s.status IN ('approved', 'paid') AND DATE(vo.date_add) BETWEEN s.period_from AND s.period_to ORDER BY s.id_settlement LIMIT 1)";
        if (!empty($f['unsettled'])) {
            $w[] = $settled . ' IS NULL';
        }

        return $this->db->executeS('SELECT vo.*, v.name AS vendor_name, v.commission_rate, v.commission_fixed,
                o.reference AS order_reference, o.id_customer, ROUND(o.total_products + o.total_shipping_tax_excl, 6) AS parent_total,
                CONCAT(c.firstname, \' \', c.lastname) AS customer_name,
                ' . $settled . ' AS id_settlement_locked
            FROM ' . self::t('bxmp_vendor_order') . ' vo
            INNER JOIN ' . self::t('bxmp_vendor') . ' v ON v.id_vendor = vo.id_vendor
            LEFT JOIN ' . self::t('orders') . ' o ON o.id_order = vo.id_order
            LEFT JOIN ' . self::t('customer') . ' c ON c.id_customer = o.id_customer
            WHERE ' . implode(' AND ', $w) . '
            ORDER BY vo.date_add DESC, vo.id_vendor_order DESC LIMIT ' . max(0, $offset) . ', ' . max(1, $limit)) ?: [];
    }

    /** @return array<int, array<int, array<string, mixed>>> id_vendor_order => lines */
    public function linesFor(array $idVendorOrders): array
    {
        $ids = array_filter(array_map('intval', $idVendorOrders));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->executeS('SELECT * FROM ' . self::t('bxmp_vendor_order_line') . ' WHERE id_vendor_order IN (' . implode(',', $ids) . ') ORDER BY id_vendor_order_line') ?: [] as $l) {
            $out[(int) $l['id_vendor_order']][] = $l;
        }

        return $out;
    }

    public function adjustments(?Period $p, ?int $idVendor = null): array
    {
        $w = ['1'];
        if ($p) {
            $w[] = "a.date_add BETWEEN '" . pSQL($p->fromSql()) . "' AND '" . pSQL($p->toSql()) . "'";
        }
        if ($idVendor) {
            $w[] = 'a.id_vendor = ' . (int) $idVendor;
        }

        return $this->db->executeS('SELECT a.*, v.name AS vendor_name, vo.reference AS vendor_order_reference
            FROM ' . self::t('bxmp_commission_adjustment') . ' a
            INNER JOIN ' . self::t('bxmp_vendor') . ' v ON v.id_vendor = a.id_vendor
            LEFT JOIN ' . self::t('bxmp_vendor_order') . ' vo ON vo.id_vendor_order = a.id_vendor_order
            WHERE ' . implode(' AND ', $w) . ' ORDER BY a.date_add DESC, a.id_adjustment DESC') ?: [];
    }

    public function settlements(array $f = []): array
    {
        $w = ['1'];
        if (!empty($f['id_vendor'])) {
            $w[] = 's.id_vendor = ' . (int) $f['id_vendor'];
        }
        if (!empty($f['status']) && in_array($f['status'], ['draft', 'approved', 'paid'], true)) {
            $w[] = "s.status = '" . pSQL($f['status']) . "'";
        }
        if (!empty($f['period'])) {
            /** @var Period $p */
            $p = $f['period'];
            $w[] = "s.period_to >= '" . pSQL($p->from->format('Y-m-d')) . "' AND s.period_from <= '" . pSQL($p->to->format('Y-m-d')) . "'";
        }

        return $this->db->executeS('SELECT s.*, v.name AS vendor_name, v.payout_account FROM ' . self::t('bxmp_settlement') . ' s
            INNER JOIN ' . self::t('bxmp_vendor') . ' v ON v.id_vendor = s.id_vendor
            WHERE ' . implode(' AND ', $w) . ' ORDER BY s.period_from DESC, s.id_settlement DESC') ?: [];
    }

    public function settlement(int $id): ?array
    {
        $row = $this->db->getRow('SELECT s.*, v.name AS vendor_name, v.payout_account, v.commission_rate, v.commission_fixed FROM ' . self::t('bxmp_settlement') . ' s
            INNER JOIN ' . self::t('bxmp_vendor') . ' v ON v.id_vendor = s.id_vendor WHERE s.id_settlement = ' . (int) $id);

        return $row ?: null;
    }

    /** Approved/paid settlement of the vendor whose period overlaps [from, to] (excluding $exceptId). */
    public function overlappingLocked(int $idVendor, string $from, string $to, int $exceptId = 0): ?array
    {
        $row = $this->db->getRow('SELECT * FROM ' . self::t('bxmp_settlement') . ' WHERE id_vendor = ' . (int) $idVendor . "
            AND status IN ('approved', 'paid') AND period_to >= '" . pSQL($from) . "' AND period_from <= '" . pSQL($to) . "'
            AND id_settlement <> " . (int) $exceptId . ' ORDER BY id_settlement');

        return $row ?: null;
    }

    public function findDraft(int $idVendor, string $from, string $to): int
    {
        return (int) $this->db->getValue('SELECT id_settlement FROM ' . self::t('bxmp_settlement') . ' WHERE id_vendor = ' . (int) $idVendor . "
            AND status = 'draft' AND period_from = '" . pSQL($from) . "' AND period_to = '" . pSQL($to) . "'");
    }

    /** Is a datetime of this vendor inside an approved/paid settlement? */
    public function isLocked(int $idVendor, string $datetime): bool
    {
        return (bool) $this->db->getValue('SELECT 1 FROM ' . self::t('bxmp_settlement') . ' WHERE id_vendor = ' . (int) $idVendor . "
            AND status IN ('approved', 'paid') AND '" . pSQL(substr($datetime, 0, 10)) . "' BETWEEN period_from AND period_to");
    }

    public function vendors(): array
    {
        return $this->db->executeS('SELECT * FROM ' . self::t('bxmp_vendor') . ' ORDER BY name') ?: [];
    }

    /**
     * Commercial conditions overview: every vendor with terms, shipping rules, offers and order activity.
     */
    public function conditions(): array
    {
        $vendors = $this->db->executeS('SELECT v.*,
                (SELECT COUNT(*) FROM ' . self::t('bxmp_offer') . ' o WHERE o.id_vendor = v.id_vendor) AS offers,
                (SELECT COUNT(*) FROM ' . self::t('bxmp_offer') . ' o WHERE o.id_vendor = v.id_vendor AND o.active = 1 AND o.quantity > 0) AS live_offers,
                (SELECT COUNT(*) FROM ' . self::t('bxmp_vendor_user') . ' u WHERE u.id_vendor = v.id_vendor AND u.active = 1) AS users,
                (SELECT COUNT(*) FROM ' . self::t('bxmp_vendor_order') . ' vo WHERE vo.id_vendor = v.id_vendor) AS vendor_orders,
                (SELECT MAX(vo.date_add) FROM ' . self::t('bxmp_vendor_order') . ' vo WHERE vo.id_vendor = v.id_vendor) AS last_order,
                (SELECT COALESCE(SUM(s.net_payout), 0) FROM ' . self::t('bxmp_settlement') . " s WHERE s.id_vendor = v.id_vendor AND s.status = 'approved') AS approved_unpaid
            FROM " . self::t('bxmp_vendor') . ' v ORDER BY v.name') ?: [];
        $rules = [];
        foreach ($this->db->executeS('SELECT r.*, c.iso_code FROM ' . self::t('bxmp_shipping_rule') . ' r
            LEFT JOIN ' . self::t('country') . ' c ON c.id_country = r.id_country ORDER BY r.id_vendor, r.priority DESC, r.id_shipping_rule') ?: [] as $r) {
            $rules[(int) $r['id_vendor']][] = $r;
        }
        foreach ($vendors as &$v) {
            $v['shipping_rules'] = $rules[(int) $v['id_vendor']] ?? [];
            $v['shipping_summary'] = self::shippingSummary($v['shipping_rules']);
        }

        return $vendors;
    }

    public static function shippingSummary(array $rules): string
    {
        if (!$rules) {
            return 'No rules: free shipping everywhere';
        }
        $parts = [];
        foreach ($rules as $r) {
            $where = ((int) $r['id_country'] ? ($r['iso_code'] ?: '#' . $r['id_country']) : 'All') . ($r['zipcode_pattern'] ? ' zip ' . $r['zipcode_pattern'] : '');
            if ((int) $r['excluded']) {
                $parts[] = $where . ': no delivery';
                continue;
            }
            $s = sprintf('%s: €%.2f', $where, (float) $r['base_cost']);
            if ($r['reduced_threshold'] !== null) {
                $s .= sprintf(', €%.2f ≥ €%.2f', (float) $r['reduced_cost'], (float) $r['reduced_threshold']);
            }
            if ($r['free_threshold'] !== null) {
                $s .= sprintf(', free ≥ €%.2f', (float) $r['free_threshold']);
            }
            $parts[] = $s;
        }

        return implode(' | ', $parts);
    }
}

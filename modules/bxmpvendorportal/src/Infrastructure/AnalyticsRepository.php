<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Infrastructure;

use BrainX\MarketplaceVendor\Domain\Period;

/**
 * Vendor-scoped analytics (SQL aggregates over bxmp_vendor_order / bxmp_vendor_order_line by date_add).
 * Every query is filtered on id_vendor — callers pass the vendor of the authenticated membership only.
 *
 * Definitions (tax excluded, as everywhere in the marketplace engine):
 *  GMV            = Σ total_paid (products + shipping) of non-cancelled sub-orders
 *  net sales      = Σ vendor_payout (GMV − commission)
 *  commission     = Σ commission_amount
 *  orders         = number of non-cancelled sub-orders; AOV = GMV / orders
 *  items sold     = Σ line quantity of non-cancelled sub-orders
 */
final class AnalyticsRepository
{
    private function db(): \Db
    {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_);
    }

    private function t(string $table): string
    {
        return '`' . _DB_PREFIX_ . $table . '`';
    }

    private function where(int $idVendor, Period $p, bool $excludeCancelled = true, string $alias = 'vo'): string
    {
        return $alias . '.id_vendor = ' . (int) $idVendor
            . ' AND ' . $alias . '.date_add BETWEEN \'' . pSQL($p->fromSql()) . '\' AND \'' . pSQL($p->toSql()) . '\''
            . ($excludeCancelled ? ' AND ' . $alias . '.status <> \'cancelled\'' : '');
    }

    /** @return array{gmv:float,net:float,commission:float,orders:int,aov:float,items:int,cancelled:int} */
    public function kpis(int $idVendor, Period $p): array
    {
        $row = $this->db()->getRow('SELECT COUNT(*) AS orders, COALESCE(SUM(vo.total_paid),0) AS gmv,
                COALESCE(SUM(vo.vendor_payout),0) AS net, COALESCE(SUM(vo.commission_amount),0) AS commission
            FROM ' . $this->t('bxmp_vendor_order') . ' vo WHERE ' . $this->where($idVendor, $p)) ?: [];
        $items = (int) $this->db()->getValue('SELECT COALESCE(SUM(l.quantity),0) FROM ' . $this->t('bxmp_vendor_order_line') . ' l
            INNER JOIN ' . $this->t('bxmp_vendor_order') . ' vo ON vo.id_vendor_order = l.id_vendor_order
            WHERE ' . $this->where($idVendor, $p));
        $cancelled = (int) $this->db()->getValue('SELECT COUNT(*) FROM ' . $this->t('bxmp_vendor_order') . ' vo
            WHERE ' . $this->where($idVendor, $p, false) . ' AND vo.status = \'cancelled\'');
        $orders = (int) ($row['orders'] ?? 0);
        $gmv = (float) ($row['gmv'] ?? 0);

        return [
            'gmv' => round($gmv, 2),
            'net' => round((float) ($row['net'] ?? 0), 2),
            'commission' => round((float) ($row['commission'] ?? 0), 2),
            'orders' => $orders,
            'aov' => $orders > 0 ? round($gmv / $orders, 2) : 0.0,
            'items' => $items,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Gap-free time series for the period buckets.
     *
     * @return array{labels:string[], gmv:float[], net:float[], commission:float[], orders:int[]}
     */
    public function trend(int $idVendor, Period $p): array
    {
        $fmt = Period::sqlBucketFormat($p->granularity);
        $rows = $this->db()->executeS('SELECT DATE_FORMAT(vo.date_add, \'' . pSQL($fmt) . '\') AS bucket, COUNT(*) AS orders,
                SUM(vo.total_paid) AS gmv, SUM(vo.vendor_payout) AS net, SUM(vo.commission_amount) AS commission
            FROM ' . $this->t('bxmp_vendor_order') . ' vo WHERE ' . $this->where($idVendor, $p) . '
            GROUP BY bucket ORDER BY bucket') ?: [];
        $byBucket = [];
        foreach ($rows as $r) {
            $byBucket[(string) $r['bucket']] = $r;
        }
        $out = ['labels' => [], 'gmv' => [], 'net' => [], 'commission' => [], 'orders' => []];
        foreach ($p->buckets() as $b) {
            $r = $byBucket[$b] ?? null;
            $out['labels'][] = $b;
            $out['gmv'][] = round((float) ($r['gmv'] ?? 0), 2);
            $out['net'][] = round((float) ($r['net'] ?? 0), 2);
            $out['commission'][] = round((float) ($r['commission'] ?? 0), 2);
            $out['orders'][] = (int) ($r['orders'] ?? 0);
        }

        return $out;
    }

    /** Best-selling SKUs (by units, then revenue) of the period. */
    public function bestSellers(int $idVendor, Period $p, int $limit = 10): array
    {
        return array_map(static function (array $r): array {
            $r['qty'] = (int) $r['qty'];
            $r['orders'] = (int) $r['orders'];
            $r['revenue'] = round((float) $r['revenue'], 2);
            $r['avg_price'] = $r['qty'] > 0 ? round($r['revenue'] / $r['qty'], 2) : 0.0;

            return $r;
        }, $this->db()->executeS('SELECT l.id_product, l.id_product_attribute, MAX(l.product_name) AS product_name, MAX(l.vendor_sku) AS vendor_sku,
                SUM(l.quantity) AS qty, SUM(l.quantity * l.unit_price) AS revenue, COUNT(DISTINCT vo.id_vendor_order) AS orders
            FROM ' . $this->t('bxmp_vendor_order_line') . ' l
            INNER JOIN ' . $this->t('bxmp_vendor_order') . ' vo ON vo.id_vendor_order = l.id_vendor_order
            WHERE ' . $this->where($idVendor, $p) . '
            GROUP BY l.id_product, l.id_product_attribute
            ORDER BY qty DESC, revenue DESC LIMIT ' . max(1, min(100, $limit))) ?: []);
    }

    /** @return array<string, array{count:int, amount:float}> every status (zero-filled), cancelled included */
    public function statusBreakdown(int $idVendor, ?Period $p = null): array
    {
        $where = $p ? $this->where($idVendor, $p, false) : 'vo.id_vendor = ' . (int) $idVendor;
        $rows = $this->db()->executeS('SELECT vo.status, COUNT(*) AS cnt, COALESCE(SUM(vo.total_paid),0) AS amount
            FROM ' . $this->t('bxmp_vendor_order') . ' vo WHERE ' . $where . ' GROUP BY vo.status') ?: [];
        $out = [];
        foreach (\BxmpVendorOrder::STATUSES as $s) {
            $out[$s] = ['count' => 0, 'amount' => 0.0];
        }
        foreach ($rows as $r) {
            $out[(string) $r['status']] = ['count' => (int) $r['cnt'], 'amount' => round((float) $r['amount'], 2)];
        }

        return $out;
    }
}

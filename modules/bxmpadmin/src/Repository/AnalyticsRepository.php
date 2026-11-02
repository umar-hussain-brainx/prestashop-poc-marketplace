<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Repository;

use BrainX\Marketplace\Domain\Pricing\OfferSnapshot;
use BrainX\Marketplace\Domain\Pricing\PriceComparator;
use BrainX\MarketplaceAdmin\Domain\Period;

/**
 * SQL aggregates for the central marketplace dashboard (scope module 9).
 *
 * Definitions (all amounts tax excluded, vendor orders = bxmp_vendor_order, cancelled ones excluded unless stated):
 *  - GMV                 Σ vendor order total_paid (products + shipping)
 *  - commission revenue  Σ commission_amount − Σ commission adjustments (adjustment > 0 is a credit to the vendor)
 *  - vendor payouts      Σ vendor_payout + Σ adjustments
 *  - orders              distinct PrestaShop parent orders; vendor orders = sub-orders
 *  - new customers       customers whose first marketplace order (for the vendor filter) falls in the period
 *  - conversion rate     orders / visits (native ps_connections); cart abandonment = carts with products and no order
 */
final class AnalyticsRepository
{
    private \Db $db;

    public function __construct(?\Db $db = null)
    {
        $this->db = $db ?? \Db::getInstance(_PS_USE_SQL_SLAVE_);
    }

    private static function t(string $table): string
    {
        return '`' . _DB_PREFIX_ . $table . '`';
    }

    private function voWhere(Period $p, ?int $idVendor, string $alias = 'vo'): string
    {
        $w = $alias . ".date_add BETWEEN '" . pSQL($p->fromSql()) . "' AND '" . pSQL($p->toSql()) . "'";
        if ($idVendor) {
            $w .= ' AND ' . $alias . '.id_vendor = ' . (int) $idVendor;
        }

        return $w;
    }

    /** @return array<string, float|int|null> */
    public function kpis(Period $p, ?int $idVendor = null): array
    {
        $vo = self::t('bxmp_vendor_order');
        $row = $this->db->getRow("SELECT
                COALESCE(SUM(IF(vo.status <> 'cancelled', vo.total_paid, 0)), 0) AS gmv,
                COALESCE(SUM(IF(vo.status <> 'cancelled', vo.total_products, 0)), 0) AS products_value,
                COALESCE(SUM(IF(vo.status <> 'cancelled', vo.total_shipping, 0)), 0) AS shipping_value,
                COALESCE(SUM(IF(vo.status <> 'cancelled', vo.commission_amount, 0)), 0) AS commission,
                COALESCE(SUM(IF(vo.status <> 'cancelled', vo.vendor_payout, 0)), 0) AS payouts,
                COALESCE(SUM(vo.status <> 'cancelled'), 0) AS vendor_orders,
                COALESCE(SUM(vo.status = 'cancelled'), 0) AS cancelled_orders,
                COUNT(DISTINCT IF(vo.status <> 'cancelled', vo.id_order, NULL)) AS orders,
                COUNT(DISTINCT IF(vo.status <> 'cancelled', o.id_customer, NULL)) AS customers
            FROM $vo vo LEFT JOIN " . self::t('orders') . ' o ON o.id_order = vo.id_order
            WHERE ' . $this->voWhere($p, $idVendor)) ?: [];

        $adj = (float) $this->db->getValue('SELECT COALESCE(SUM(amount), 0) FROM ' . self::t('bxmp_commission_adjustment') . " a
            WHERE a.date_add BETWEEN '" . pSQL($p->fromSql()) . "' AND '" . pSQL($p->toSql()) . "'" . ($idVendor ? ' AND a.id_vendor = ' . (int) $idVendor : ''));

        $newCustomers = (int) $this->db->getValue("SELECT COUNT(*) FROM (
                SELECT o.id_customer, MIN(vo.date_add) AS first_order
                FROM $vo vo INNER JOIN " . self::t('orders') . " o ON o.id_order = vo.id_order
                WHERE vo.status <> 'cancelled'" . ($idVendor ? ' AND vo.id_vendor = ' . (int) $idVendor : '') . "
                GROUP BY o.id_customer
                HAVING first_order BETWEEN '" . pSQL($p->fromSql()) . "' AND '" . pSQL($p->toSql()) . "') t");

        $offerWhere = 'o.active = 1 AND o.quantity > 0 AND v.active = 1';
        $offers = $this->db->getRow('SELECT COUNT(*) AS offers, COUNT(DISTINCT o.id_product, o.id_product_attribute) AS offered_products
            FROM ' . self::t('bxmp_offer') . ' o INNER JOIN ' . self::t('bxmp_vendor') . ' v ON v.id_vendor = o.id_vendor
            WHERE ' . $offerWhere . ($idVendor ? ' AND o.id_vendor = ' . (int) $idVendor : '')) ?: ['offers' => 0, 'offered_products' => 0];
        $global = $this->db->getRow('SELECT COUNT(*) AS offers, COUNT(DISTINCT o.id_product, o.id_product_attribute) AS offered_products
            FROM ' . self::t('bxmp_offer') . ' o INNER JOIN ' . self::t('bxmp_vendor') . ' v ON v.id_vendor = o.id_vendor WHERE ' . $offerWhere) ?: ['offers' => 0, 'offered_products' => 0];
        $activeProducts = (int) $this->db->getValue('SELECT COUNT(*) FROM ' . self::t('product') . ' WHERE active = 1');
        $activeVendors = (int) $this->db->getValue('SELECT COUNT(*) FROM ' . self::t('bxmp_vendor') . ' WHERE active = 1');

        $traffic = $this->db->getRow('SELECT COUNT(*) AS visits, COUNT(DISTINCT id_guest) AS visitors FROM ' . self::t('connections') . "
            WHERE date_add BETWEEN '" . pSQL($p->fromSql()) . "' AND '" . pSQL($p->toSql()) . "'") ?: ['visits' => 0, 'visitors' => 0];

        $carts = $this->db->getRow('SELECT COUNT(*) AS carts, COALESCE(SUM(o.id_order IS NULL), 0) AS abandoned FROM ' . self::t('cart') . ' c
            LEFT JOIN ' . self::t('orders') . " o ON o.id_cart = c.id_cart
            WHERE c.date_add BETWEEN '" . pSQL($p->fromSql()) . "' AND '" . pSQL($p->toSql()) . "'
              AND EXISTS (SELECT 1 FROM " . self::t('cart_product') . ' cp WHERE cp.id_cart = c.id_cart)') ?: ['carts' => 0, 'abandoned' => 0];

        $gmv = (float) ($row['gmv'] ?? 0);
        $orders = (int) ($row['orders'] ?? 0);
        $commission = (float) ($row['commission'] ?? 0);
        $visits = (int) $traffic['visits'];
        $vendorOrders = (int) ($row['vendor_orders'] ?? 0);
        $allVendorOrders = $vendorOrders + (int) ($row['cancelled_orders'] ?? 0);

        return [
            'gmv' => round($gmv, 2),
            'products_value' => round((float) ($row['products_value'] ?? 0), 2),
            'shipping_value' => round((float) ($row['shipping_value'] ?? 0), 2),
            'commission' => round($commission, 2),
            'adjustments' => round($adj, 2),
            'commission_revenue' => round($commission - $adj, 2),
            'take_rate' => $gmv > 0 ? round(($commission - $adj) / $gmv * 100, 2) : null,
            'payouts' => round((float) ($row['payouts'] ?? 0) + $adj, 2),
            'orders' => $orders,
            'vendor_orders' => $vendorOrders,
            'cancelled_orders' => (int) ($row['cancelled_orders'] ?? 0),
            'cancellation_rate' => $allVendorOrders > 0 ? round((int) $row['cancelled_orders'] / $allVendorOrders * 100, 2) : null,
            'vendors_per_order' => $orders > 0 ? round($vendorOrders / $orders, 2) : null,
            'aov' => $orders > 0 ? round($gmv / $orders, 2) : null,
            'customers' => (int) ($row['customers'] ?? 0),
            'new_customers' => $newCustomers,
            'returning_customers' => max(0, (int) ($row['customers'] ?? 0) - $newCustomers),
            'active_offers' => (int) $offers['offers'],
            'offered_products' => (int) $offers['offered_products'],
            'offers_per_product' => (int) $global['offered_products'] > 0 ? round((int) $global['offers'] / (int) $global['offered_products'], 2) : null,
            'active_products' => $activeProducts,
            'active_vendors' => $activeVendors,
            'visits' => $visits,
            'visitors' => (int) $traffic['visitors'],
            'conversion_rate' => $visits > 0 ? round($orders / $visits * 100, 2) : null,
            'carts' => (int) $carts['carts'],
            'abandoned_carts' => (int) $carts['abandoned'],
            'cart_abandonment' => (int) $carts['carts'] > 0 ? round((int) $carts['abandoned'] / (int) $carts['carts'] * 100, 2) : null,
        ];
    }

    /** @return array<string, array{gmv: float, commission: float, orders: int}> bucket => values (gap-free) */
    public function trend(Period $p, ?int $idVendor = null): array
    {
        $bucket = $p->bucketSqlExpr('vo.date_add');
        $rows = $this->db->executeS("SELECT $bucket AS bucket,
                SUM(IF(vo.status <> 'cancelled', vo.total_paid, 0)) AS gmv,
                SUM(IF(vo.status <> 'cancelled', vo.commission_amount, 0)) AS commission,
                COUNT(DISTINCT IF(vo.status <> 'cancelled', vo.id_order, NULL)) AS orders
            FROM " . self::t('bxmp_vendor_order') . ' vo WHERE ' . $this->voWhere($p, $idVendor) . ' GROUP BY bucket') ?: [];
        $out = [];
        foreach ($p->buckets() as $b) {
            $out[$b] = ['gmv' => 0.0, 'commission' => 0.0, 'orders' => 0];
        }
        foreach ($rows as $r) {
            $out[$r['bucket']] = ['gmv' => round((float) $r['gmv'], 2), 'commission' => round((float) $r['commission'], 2), 'orders' => (int) $r['orders']];
        }
        ksort($out);

        return $out;
    }

    /**
     * Per-vendor league table for the period (all vendors, even without sales).
     *
     * Fulfilment = shipped|delivered / non-cancelled; time to ship = date_upd − date_add of shipped/delivered orders
     * (approximation: the schema has no dedicated shipped_at column).
     */
    public function league(Period $p, ?int $idVendor = null): array
    {
        $rows = $this->db->executeS("SELECT v.id_vendor, v.name, v.code, v.active, v.commission_rate, v.commission_fixed,
                COUNT(vo.id_vendor_order) AS all_orders,
                COALESCE(SUM(vo.status <> 'cancelled'), 0) AS vendor_orders,
                COALESCE(SUM(vo.status = 'cancelled'), 0) AS cancelled,
                COALESCE(SUM(vo.status IN ('shipped', 'delivered')), 0) AS fulfilled,
                COALESCE(SUM(IF(vo.status <> 'cancelled', vo.total_paid, 0)), 0) AS gmv,
                COALESCE(SUM(IF(vo.status <> 'cancelled', vo.commission_amount, 0)), 0) AS commission,
                COALESCE(SUM(IF(vo.status <> 'cancelled', vo.vendor_payout, 0)), 0) AS payout,
                AVG(IF(vo.status <> 'cancelled', vo.total_shipping, NULL)) AS avg_shipping,
                AVG(IF(vo.status <> 'cancelled', vo.total_paid, NULL)) AS avg_order,
                AVG(IF(vo.status IN ('shipped', 'delivered'), TIMESTAMPDIFF(MINUTE, vo.date_add, vo.date_upd) / 60, NULL)) AS avg_hours_to_ship
            FROM " . self::t('bxmp_vendor') . ' v
            LEFT JOIN ' . self::t('bxmp_vendor_order') . " vo ON vo.id_vendor = v.id_vendor
                AND vo.date_add BETWEEN '" . pSQL($p->fromSql()) . "' AND '" . pSQL($p->toSql()) . "'
            " . ($idVendor ? 'WHERE v.id_vendor = ' . (int) $idVendor : '') . '
            GROUP BY v.id_vendor ORDER BY gmv DESC, v.name') ?: [];

        $totalGmv = array_sum(array_map(static fn ($r) => (float) $r['gmv'], $rows));
        $buyBox = $this->buyBoxShares();
        foreach ($rows as &$r) {
            $live = (int) $r['vendor_orders'];
            $all = (int) $r['all_orders'];
            $r['gmv'] = round((float) $r['gmv'], 2);
            $r['commission'] = round((float) $r['commission'], 2);
            $r['payout'] = round((float) $r['payout'], 2);
            $r['gmv_share'] = $totalGmv > 0 ? round($r['gmv'] / $totalGmv * 100, 1) : null;
            $r['avg_shipping'] = $r['avg_shipping'] !== null ? round((float) $r['avg_shipping'], 2) : null;
            $r['avg_order'] = $r['avg_order'] !== null ? round((float) $r['avg_order'], 2) : null;
            $r['avg_hours_to_ship'] = $r['avg_hours_to_ship'] !== null ? round((float) $r['avg_hours_to_ship'], 1) : null;
            $r['fulfillment_rate'] = $live > 0 ? round((int) $r['fulfilled'] / $live * 100, 1) : null;
            $r['cancellation_rate'] = $all > 0 ? round((int) $r['cancelled'] / $all * 100, 1) : null;
            $bb = $buyBox[(int) $r['id_vendor']] ?? ['wins' => 0, 'contested' => 0, 'offers' => 0];
            $r['offers'] = $bb['offers'];
            $r['buybox_wins'] = $bb['wins'];
            $r['buybox_contested'] = $bb['contested'];
            $r['buybox_share'] = $bb['contested'] > 0 ? round($bb['wins'] / $bb['contested'] * 100, 1) : null;
        }

        return $rows;
    }

    /**
     * Current buy-box snapshot: for every product/combination offered by ≥ 2 active vendors, the vendor with the lowest
     * effective price (discount window honoured, core PriceComparator) wins; ties go to the lowest offer id.
     *
     * @return array<int, array{wins:int, contested:int, offers:int}>
     */
    public function buyBoxShares(): array
    {
        $rows = $this->db->executeS('SELECT o.*, v.active AS vendor_active FROM ' . self::t('bxmp_offer') . ' o
            INNER JOIN ' . self::t('bxmp_vendor') . ' v ON v.id_vendor = o.id_vendor
            WHERE o.active = 1 AND o.quantity > 0 AND v.active = 1 ORDER BY o.id_offer') ?: [];
        $cmp = new PriceComparator();
        $now = new \DateTimeImmutable();
        $byKey = [];
        $out = [];
        foreach ($rows as $r) {
            $snap = OfferSnapshot::fromArray($r);
            $byKey[$snap->idProduct . '-' . $snap->idProductAttribute][] = [$snap->idVendor, $cmp->effectivePrice($snap, $now)];
            $out[$snap->idVendor] ??= ['wins' => 0, 'contested' => 0, 'offers' => 0];
            ++$out[$snap->idVendor]['offers'];
        }
        foreach ($byKey as $list) {
            $vendors = array_unique(array_column($list, 0));
            if (count($vendors) < 2) {
                continue;
            }
            $best = null;
            foreach ($list as [$idVendor, $price]) {
                ++$out[$idVendor]['contested'];
                if ($best === null || $price < $best[1] - 1e-9) {
                    $best = [$idVendor, $price];
                }
            }
            ++$out[$best[0]]['wins'];
        }

        return $out;
    }

    public function topProducts(Period $p, ?int $idVendor = null, int $limit = 10): array
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        return $this->db->executeS('SELECT l.id_product, COALESCE(pl.name, MAX(l.product_name)) AS name, p.reference,
                SUM(l.quantity) AS quantity, ROUND(SUM(l.unit_price * l.quantity), 2) AS revenue,
                ROUND(SUM(l.commission_amount), 2) AS commission, COUNT(DISTINCT vo.id_vendor) AS vendors, COUNT(DISTINCT vo.id_order) AS orders
            FROM ' . self::t('bxmp_vendor_order_line') . ' l
            INNER JOIN ' . self::t('bxmp_vendor_order') . ' vo ON vo.id_vendor_order = l.id_vendor_order
            LEFT JOIN ' . self::t('product') . ' p ON p.id_product = l.id_product
            LEFT JOIN ' . self::t('product_lang') . ' pl ON pl.id_product = l.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = 1
            WHERE vo.status <> \'cancelled\' AND ' . $this->voWhere($p, $idVendor) . '
            GROUP BY l.id_product ORDER BY revenue DESC, quantity DESC LIMIT ' . max(1, $limit)) ?: [];
    }

    public function topCategories(Period $p, ?int $idVendor = null, int $limit = 10): array
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        return $this->db->executeS('SELECT c.id_category, COALESCE(cl.name, CONCAT(\'#\', c.id_category)) AS name,
                SUM(l.quantity) AS quantity, ROUND(SUM(l.unit_price * l.quantity), 2) AS revenue,
                ROUND(SUM(l.commission_amount), 2) AS commission, COUNT(DISTINCT l.id_product) AS products, COUNT(DISTINCT vo.id_order) AS orders
            FROM ' . self::t('bxmp_vendor_order_line') . ' l
            INNER JOIN ' . self::t('bxmp_vendor_order') . ' vo ON vo.id_vendor_order = l.id_vendor_order
            INNER JOIN ' . self::t('product') . ' p ON p.id_product = l.id_product
            INNER JOIN ' . self::t('category') . ' c ON c.id_category = p.id_category_default
            LEFT JOIN ' . self::t('category_lang') . ' cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = 1
            WHERE vo.status <> \'cancelled\' AND ' . $this->voWhere($p, $idVendor) . '
            GROUP BY c.id_category ORDER BY revenue DESC LIMIT ' . max(1, $limit)) ?: [];
    }

    /** Status breakdown of vendor orders in the period. */
    public function statusBreakdown(Period $p, ?int $idVendor = null): array
    {
        $rows = $this->db->executeS('SELECT vo.status, COUNT(*) AS n, ROUND(SUM(vo.total_paid), 2) AS amount FROM ' . self::t('bxmp_vendor_order') . ' vo
            WHERE ' . $this->voWhere($p, $idVendor) . ' GROUP BY vo.status') ?: [];
        $out = array_fill_keys(\BxmpVendorOrder::STATUSES, ['n' => 0, 'amount' => 0.0]);
        foreach ($rows as $r) {
            $out[$r['status']] = ['n' => (int) $r['n'], 'amount' => (float) $r['amount']];
        }

        return $out;
    }
}

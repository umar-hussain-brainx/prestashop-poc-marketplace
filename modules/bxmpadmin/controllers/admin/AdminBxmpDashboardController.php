<?php
/**
 * Marketplace dashboard (scope module 9): aggregate + vendor-level KPIs, GMV trend, vendor league,
 * top products / categories. Every block is exportable to CSV.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpadmin/classes/BxmpAdminPageController.php';

use BrainX\MarketplaceAdmin\Domain\SvgChart;
use BrainX\MarketplaceAdmin\Repository\AnalyticsRepository;

class AdminBxmpDashboardController extends BxmpAdminPageController
{
    public const KPI_LABELS = [
        'gmv' => 'GMV (tax excl.)',
        'commission_revenue' => 'Commission revenue (net of adjustments)',
        'commission' => 'Commissions on orders',
        'adjustments' => 'Commission adjustments (credit to vendors)',
        'take_rate' => 'Take rate %',
        'payouts' => 'Vendor payouts (incl. adjustments)',
        'orders' => 'Orders',
        'vendor_orders' => 'Vendor orders',
        'cancelled_orders' => 'Cancelled vendor orders',
        'cancellation_rate' => 'Cancellation rate %',
        'vendors_per_order' => 'Vendors per order',
        'aov' => 'Average order value',
        'customers' => 'Customers',
        'new_customers' => 'New customers',
        'returning_customers' => 'Returning customers',
        'active_offers' => 'Active offers',
        'offered_products' => 'Products with active offers',
        'offers_per_product' => 'Offers per product',
        'active_products' => 'Active catalog products',
        'active_vendors' => 'Active vendors',
        'visits' => 'Visits',
        'visitors' => 'Visitors',
        'conversion_rate' => 'Conversion rate % (orders / visits)',
        'carts' => 'Carts with products',
        'abandoned_carts' => 'Abandoned carts',
        'cart_abandonment' => 'Cart abandonment %',
        'products_value' => 'Products value',
        'shipping_value' => 'Shipping value',
    ];

    private AnalyticsRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new AnalyticsRepository();
        $this->meta_title = ['Marketplace dashboard'];
    }

    public static function leagueColumns(): array
    {
        return [
            'name' => ['Vendor'],
            'status_html' => ['Status', 'html'],
            'vendor_orders' => ['Vendor orders', 'int'],
            'gmv' => ['GMV', 'money'],
            'gmv_share' => ['GMV share', 'pct'],
            'commission' => ['Commission', 'money'],
            'payout' => ['Payout', 'money'],
            'avg_order' => ['Avg order', 'money'],
            'avg_shipping' => ['Avg shipping', 'money'],
            'fulfillment_rate' => ['Fulfilment', 'pct'],
            'avg_hours_to_ship' => ['Avg h to ship', 'num'],
            'cancellation_rate' => ['Cancellation', 'pct'],
            'offers' => ['Live offers', 'int'],
            'buybox_share' => ['Buy-box share', 'pct'],
        ];
    }

    protected function handleActions(): void
    {
        $p = $this->period();
        $v = $this->vendorId();
        $this->maybeExport([
            'kpis' => function () use ($p, $v) {
                $cur = $this->repo->kpis($p, $v);
                $prev = $this->repo->kpis($p->previous(), $v);
                $rows = [];
                foreach (self::KPI_LABELS as $k => $label) {
                    $rows[] = ['kpi' => $k, 'label' => $label, 'value' => $cur[$k], 'previous' => $prev[$k], 'period' => $p->label()];
                }

                return [['kpi' => 'KPI', 'label' => 'Label', 'value' => 'Value', 'previous' => 'Previous period', 'period' => 'Period'], $rows];
            },
            'trend' => function () use ($p, $v) {
                $rows = [];
                foreach ($this->repo->trend($p, $v) as $bucket => $r) {
                    $rows[] = ['bucket' => $bucket] + $r;
                }

                return [['bucket' => 'Bucket (' . $p->granularity() . ')', 'gmv' => 'GMV', 'commission' => 'Commission', 'orders' => 'Orders'], $rows];
            },
            'league' => function () use ($p, $v) {
                $cols = self::leagueColumns();
                unset($cols['status_html']);
                $cols = ['id_vendor' => ['ID'], 'code' => ['Code']] + $cols + ['active' => ['Active'], 'commission_rate' => ['Rate %'], 'commission_fixed' => ['Fixed/line'],
                    'cancelled' => ['Cancelled'], 'fulfilled' => ['Fulfilled'], 'buybox_wins' => ['Buy-box wins'], 'buybox_contested' => ['Contested offers']];

                return [$cols, $this->repo->league($p, $v)];
            },
            'products' => fn () => [['id_product' => 'ID', 'name' => 'Product', 'reference' => 'Reference', 'quantity' => 'Qty', 'revenue' => 'Revenue', 'commission' => 'Commission', 'vendors' => 'Vendors', 'orders' => 'Orders'], $this->repo->topProducts($p, $v, 1000)],
            'categories' => fn () => [['id_category' => 'ID', 'name' => 'Category', 'quantity' => 'Qty', 'revenue' => 'Revenue', 'commission' => 'Commission', 'products' => 'Products', 'orders' => 'Orders'], $this->repo->topCategories($p, $v, 1000)],
        ]);
    }

    protected function renderPage(): string
    {
        $p = $this->period();
        $v = $this->vendorId();
        $k = $this->repo->kpis($p, $v);
        $prev = $this->repo->kpis($p->previous(), $v);
        $trend = $this->repo->trend($p, $v);
        $league = $this->repo->league($p, $v);
        $editUrl = $this->context->link->getAdminLink('AdminBxmpVendors');
        foreach ($league as &$r) {
            $r['status_html'] = $this->badge((int) $r['active'] ? 'active' : 'inactive');
        }
        unset($r);

        $tiles = [
            ['gmv', 'money', true, sprintf('products %.2f € + shipping %.2f €', $k['products_value'], $k['shipping_value'])],
            ['commission_revenue', 'money', true, sprintf('take rate %s', $k['take_rate'] === null ? '–' : $k['take_rate'] . ' %')],
            ['payouts', 'money', true, sprintf('adjustments %.2f €', $k['adjustments'])],
            ['orders', 'int', true, sprintf('%d vendor orders · %s vendors/order', $k['vendor_orders'], $k['vendors_per_order'] ?? '–')],
            ['aov', 'money', false, 'GMV / orders'],
            ['new_customers', 'int', false, sprintf('%d customers · %d returning', $k['customers'], $k['returning_customers'])],
            ['conversion_rate', 'pct', false, sprintf('%d orders / %d visits (%d visitors)', $k['orders'], $k['visits'], $k['visitors'])],
            ['cart_abandonment', 'pct', false, sprintf('%d of %d carts without order', $k['abandoned_carts'], $k['carts'])],
            ['active_offers', 'int', false, sprintf('%d products offered · %d catalog products', $k['offered_products'], $k['active_products'])],
            ['offers_per_product', 'num', false, sprintf('%d active vendors', $k['active_vendors'])],
            ['cancellation_rate', 'pct', false, sprintf('%d cancelled vendor orders', $k['cancelled_orders'])],
            ['commission', 'money', false, 'before adjustments'],
        ];
        $kpis = [];
        foreach ($tiles as [$key, $type, $main, $sub]) {
            $kpis[] = ['key' => $key, 'label' => self::KPI_LABELS[$key], 'type' => $type, 'main' => $main, 'sub' => $sub,
                'value' => $k[$key], 'delta' => in_array($key, ['active_offers', 'offers_per_product'], true) ? null : self::delta($k[$key], $prev[$key])];
        }

        $gmvShare = [];
        foreach ($league as $r) {
            if ($r['gmv'] > 0) {
                $gmvShare[$r['name']] = $r['gmv'];
            }
        }

        $html = $this->filterBar();
        $html .= $this->fetch('dashboard.tpl', [
            'kpis' => $kpis,
            'chart' => SvgChart::barLine(array_map(static fn ($t) => $t['gmv'], $trend), array_map(static fn ($t) => $t['commission'], $trend)),
            'share_chart' => $gmvShare ? SvgChart::hbars($gmvShare, 420, ' €') : '',
            'granularity' => $p->granularity(),
            'status' => $this->repo->statusBreakdown($p, $v),
            'export_kpis' => $this->url(['export' => 'kpis'] + $this->filterParams()),
            'export_trend' => $this->url(['export' => 'trend'] + $this->filterParams()),
            'vendor_name' => $v ? (BxmpVendor::getNames()[$v] ?? '') : '',
            'links' => [
                'Commissions' => $this->url($this->filterParams(), 'AdminBxmpCommissions'),
                'Settlements' => $this->url([], 'AdminBxmpSettlements'),
                'Reconciliation' => $this->url($this->filterParams(), 'AdminBxmpReconciliation'),
                'Commercial conditions' => $this->url([], 'AdminBxmpConditions'),
                'Vendors' => $editUrl,
            ],
        ]);
        $html .= $this->table('Vendor league table', self::leagueColumns(), $league, 'league', [
            'name' => 'Total',
            'vendor_orders' => array_sum(array_column($league, 'vendor_orders')),
            'gmv' => array_sum(array_column($league, 'gmv')),
            'commission' => array_sum(array_column($league, 'commission')),
            'payout' => array_sum(array_column($league, 'payout')),
        ], 'icon-trophy');
        $html .= '<div class="bxmp-two"><div>' . $this->table('Top products', [
            'name' => ['Product'], 'reference' => ['Reference'], 'quantity' => ['Qty', 'int'], 'revenue' => ['Revenue', 'money'], 'commission' => ['Commission', 'money'], 'vendors' => ['Vendors', 'int'],
        ], $this->repo->topProducts($p, $v), 'products', [], 'icon-cube') . '</div><div>' . $this->table('Top categories', [
            'name' => ['Category'], 'quantity' => ['Qty', 'int'], 'revenue' => ['Revenue', 'money'], 'commission' => ['Commission', 'money'], 'products' => ['Products', 'int'],
        ], $this->repo->topCategories($p, $v), 'categories', [], 'icon-folder') . '</div></div>';

        return $html;
    }

    private static function delta($cur, $prev): ?float
    {
        if ($cur === null || $prev === null || (float) $prev == 0.0) {
            return null;
        }

        return round(((float) $cur - (float) $prev) / abs((float) $prev) * 100, 1);
    }
}

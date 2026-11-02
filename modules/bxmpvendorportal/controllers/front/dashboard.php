<?php
/**
 * Vendor portal — dashboard: KPI tiles for a selectable period (with previous-period deltas), sales trend
 * (day / week / month buckets), best-selling products, order status breakdown. Staff users get the operational
 * view only (open orders), without financial analytics.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/classes/BxmpVendorPortalController.php';

use BrainX\MarketplaceVendor\Domain\Period;
use BrainX\MarketplaceVendor\Domain\RolePolicy;
use BrainX\MarketplaceVendor\Infrastructure\AnalyticsRepository;

class BxmpvendorportalDashboardModuleFrontController extends BxmpVendorPortalController
{
    protected string $portalPage = 'dashboard';
    protected string $permission = RolePolicy::DASHBOARD_VIEW;

    protected function renderPage(): void
    {
        $repo = new AnalyticsRepository();
        $open = $repo->statusBreakdown($this->idVendor);
        $vars = [
            'open_orders' => ['pending' => $open['pending']['count'], 'accepted' => $open['accepted']['count'], 'shipped' => $open['shipped']['count']],
            'orders_url' => $this->url('orders'),
            'status_urls' => [
                'pending' => $this->url('orders', ['status' => 'pending']),
                'accepted' => $this->url('orders', ['status' => 'accepted']),
                'shipped' => $this->url('orders', ['status' => 'shipped']),
            ],
            'analytics' => null,
        ];

        if (RolePolicy::can($this->role, RolePolicy::ANALYTICS_VIEW)) {
            $period = Period::fromRequest(
                (string) Tools::getValue('period', '30d'),
                Tools::getValue('from') ?: null,
                Tools::getValue('to') ?: null,
                Tools::getValue('granularity') ?: null
            );
            $kpis = $repo->kpis($this->idVendor, $period);
            $prev = $repo->kpis($this->idVendor, $period->previous());
            $delta = static function (float $cur, float $old): ?float {
                return $old > 0 ? round(($cur - $old) / $old * 100, 1) : null;
            };
            $tiles = [
                ['key' => 'gmv', 'label' => 'GMV', 'value' => $this->money($kpis['gmv']), 'delta' => $delta($kpis['gmv'], $prev['gmv']), 'hint' => 'Products + shipping, tax excl., cancelled excluded'],
                ['key' => 'net', 'label' => 'Net sales (after commission)', 'value' => $this->money($kpis['net']), 'delta' => $delta($kpis['net'], $prev['net']), 'hint' => 'Vendor payout'],
                ['key' => 'orders', 'label' => 'Orders', 'value' => (string) $kpis['orders'], 'delta' => $delta($kpis['orders'], $prev['orders']), 'hint' => $kpis['cancelled'] . ' cancelled'],
                ['key' => 'aov', 'label' => 'Avg order value', 'value' => $this->money($kpis['aov']), 'delta' => $delta($kpis['aov'], $prev['aov']), 'hint' => 'GMV / orders'],
                ['key' => 'commission', 'label' => 'Commission paid', 'value' => $this->money($kpis['commission']), 'delta' => $delta($kpis['commission'], $prev['commission']), 'hint' => sprintf('Rate %s %% + %s / line', rtrim(rtrim(number_format((float) $this->vendorModel->commission_rate, 2), '0'), '.'), $this->money((float) $this->vendorModel->commission_fixed))],
                ['key' => 'items', 'label' => 'Items sold', 'value' => (string) $kpis['items'], 'delta' => $delta($kpis['items'], $prev['items']), 'hint' => 'Units'],
            ];
            $trend = $repo->trend($this->idVendor, $period);
            $vars['analytics'] = [
                'period' => [
                    'preset' => $period->preset, 'granularity' => $period->granularity,
                    'from' => $period->from->format('Y-m-d'), 'to' => $period->to->format('Y-m-d'),
                    'prev_from' => $period->previous()->from->format('Y-m-d'), 'prev_to' => $period->previous()->to->format('Y-m-d'),
                ],
                'presets' => ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '365d' => 'Last 12 months', 'custom' => 'Custom'],
                'tiles' => $tiles,
                'trend_json' => json_encode($trend + ['currency' => $this->context->currency->sign], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                'trend_empty' => array_sum($trend['orders']) === 0,
                'best' => $repo->bestSellers($this->idVendor, $period, 10),
                'status' => $repo->statusBreakdown($this->idVendor, $period),
                'dashboard_url' => $this->url('dashboard'),
            ];
        }
        $this->context->smarty->assign(['dash' => $vars]);
        $this->setTemplate('module:bxmpvendorportal/views/templates/front/dashboard.tpl');
    }
}

<?php
/**
 * Commercial conditions overview: every vendor with commission terms, shipping rules, status and activity,
 * quick activate/deactivate and links to the core vendor edit form / shipping rules (full control over vendors).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpadmin/classes/BxmpAdminPageController.php';

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\MarketplaceAdmin\Repository\FinanceRepository;

class AdminBxmpConditionsController extends BxmpAdminPageController
{
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = 'Commercial conditions';
    }

    public static function columns(): array
    {
        return [
            'id_vendor' => ['ID', 'int'],
            'name_html' => ['Vendor', 'html'],
            'status_html' => ['Status', 'html'],
            'commission_rate' => ['Rate %', 'num'],
            'commission_fixed' => ['Fixed / line', 'money'],
            'example' => ['Commission on a 100 € line'],
            'shipping_summary' => ['Shipping rules'],
            'payout_account' => ['Payout account'],
            'live_offers' => ['Live offers', 'int'],
            'users' => ['Users', 'int'],
            'vendor_orders' => ['Vendor orders', 'int'],
            'last_order' => ['Last order'],
            'approved_unpaid' => ['Approved, unpaid', 'money'],
            'actions' => ['', 'html'],
        ];
    }

    private function rows(): array
    {
        $rows = (new FinanceRepository())->conditions();
        $calc = new BrainX\Marketplace\Domain\Commission\CommissionCalculator();
        foreach ($rows as &$r) {
            $id = (int) $r['id_vendor'];
            $edit = $this->context->link->getAdminLink('AdminBxmpVendors', true, [], ['id_vendor' => $id, 'updatebxmp_vendor' => 1]);
            $r['name_html'] = $this->link((string) $r['name'], $edit) . '<br><small class="text-muted">' . htmlspecialchars((string) $r['code']) . ' · ' . htmlspecialchars((string) $r['email']) . '</small>';
            $r['status'] = (int) $r['active'] ? 'active' : 'inactive';
            $r['status_html'] = $this->badge($r['status']);
            $r['example'] = sprintf('%.2f €', $calc->lineCommission(100.0, 1, BrainX\MarketplaceAdmin\Service\CommissionService::termsFromRow($r)));
            $r['actions'] = '<form method="post" class="bxmp-inline-form" action="' . htmlspecialchars($this->url(), ENT_QUOTES) . '">'
                . '<input type="hidden" name="id_vendor_toggle" value="' . $id . '">'
                . '<button type="submit" name="submitBxmpToggleVendor" value="1" class="btn btn-xs ' . ((int) $r['active'] ? 'btn-warning' : 'btn-success') . '"'
                . ' onclick="return confirm(\'' . ((int) $r['active'] ? 'Deactivate this vendor? Its offers disappear from the shop.' : 'Activate this vendor?') . '\')">'
                . ((int) $r['active'] ? 'Deactivate' : 'Activate') . '</button></form>'
                . $this->link('Edit terms', $edit, 'btn btn-default btn-xs') . ' '
                . $this->link('Shipping rules', $this->context->link->getAdminLink('AdminBxmpShippingRules', true, [], ['bxmp_shipping_ruleFilter_id_vendor' => $id]), 'btn btn-default btn-xs') . ' '
                . $this->link('Dashboard', $this->url(['id_vendor' => $id], 'AdminBxmpDashboard'), 'btn btn-default btn-xs');
            if (!(int) $r['active']) {
                $r['_class'] = 'bxmp-row-muted';
            }
        }

        return $rows;
    }

    protected function handleActions(): void
    {
        $this->maybeExport([
            'conditions' => function () {
                $cols = self::columns();
                unset($cols['name_html'], $cols['status_html'], $cols['actions']);

                return [['name' => ['Vendor'], 'code' => ['Code'], 'email' => ['Email'], 'company' => ['Company'], 'vat_number' => ['VAT'], 'status' => ['Status']] + $cols, $this->rows()];
            },
        ]);
        if (Tools::isSubmit('submitBxmpToggleVendor')) {
            if (!$this->access('edit')) {
                throw new RuntimeException('You do not have permission to edit.');
            }
            $v = new BxmpVendor((int) Tools::getValue('id_vendor_toggle'));
            if (!$v->id) {
                $this->redirectWith('Unknown vendor.', false);
            }
            $v->active = !(int) $v->active;
            $v->update();
            Logger::channel('admin')->info('Vendor ' . ($v->active ? 'activated' : 'deactivated') . ' from commercial conditions', ['id_vendor' => (int) $v->id]);
            $this->redirectWith(sprintf('Vendor "%s" %s.', $v->name, $v->active ? 'activated' : 'deactivated'));
        }
    }

    protected function renderPage(): string
    {
        $html = '<div class="alert alert-info">Commercial conditions of every vendor. Commission terms (rate % per order line + fixed amount per line'
            . (Configuration::get('BXMP_COMMISSION_ON_SHIPPING') ? ', also applied to shipping' : '') . ') are edited in the core vendor form; after a change, '
            . $this->link('Commissions', $this->url([], 'AdminBxmpCommissions')) . ' offers to recalculate unsettled orders.</div>';
        $html .= $this->table('Vendors & commercial conditions', self::columns(), $this->rows(), 'conditions', [], 'icon-briefcase', 'No vendor.');

        return $html;
    }

    protected function filterParams(): array
    {
        return [];
    }
}

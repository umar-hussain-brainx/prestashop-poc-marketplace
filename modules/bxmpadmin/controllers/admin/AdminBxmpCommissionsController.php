<?php
/**
 * Commission per vendor order + automated recalculation when a vendor's commercial terms changed
 * (only for orders not yet in an approved/paid settlement, after an explicit confirmation).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpadmin/classes/BxmpAdminPageController.php';

use BrainX\MarketplaceAdmin\Repository\FinanceRepository;
use BrainX\MarketplaceAdmin\Service\CommissionService;

class AdminBxmpCommissionsController extends BxmpAdminPageController
{
    private FinanceRepository $finance;
    private CommissionService $commissions;

    public function __construct()
    {
        parent::__construct();
        $this->finance = new FinanceRepository();
        $this->commissions = new CommissionService($this->finance);
        $this->meta_title = 'Commissions';
    }

    public static function columns(): array
    {
        return [
            'date_add' => ['Date'],
            'reference' => ['Vendor order'],
            'order_reference' => ['Order'],
            'vendor_name' => ['Vendor'],
            'customer_name' => ['Customer'],
            'status_html' => ['Status', 'html'],
            'total_products' => ['Products', 'money'],
            'total_shipping' => ['Shipping', 'money'],
            'total_paid' => ['Gross', 'money'],
            'terms' => ['Terms'],
            'commission_amount' => ['Commission', 'money'],
            'expected_commission' => ['Current terms', 'money'],
            'drift' => ['Drift', 'money'],
            'vendor_payout' => ['Payout', 'money'],
            'payment_transfer_ref' => ['Transfer ref'],
            'settlement_html' => ['Settlement', 'html'],
        ];
    }

    private function status(): ?string
    {
        $s = (string) Tools::getValue('status');

        return in_array($s, BxmpVendorOrder::STATUSES, true) ? $s : null;
    }

    protected function extraExportParams(): array
    {
        return $this->status() ? ['status' => $this->status()] : [];
    }

    private function rows(): array
    {
        $rows = $this->commissions->withExpected($this->finance->vendorOrders($this->period(), ['id_vendor' => $this->vendorId(), 'status' => $this->status()], 5000));
        foreach ($rows as &$r) {
            $r['terms'] = sprintf('%.2f %% + %.2f €', (float) $r['commission_rate'], (float) $r['commission_fixed']);
            $r['status_html'] = $this->badge((string) $r['status']);
            $r['settlement_html'] = $r['locked']
                ? $this->link('#' . $r['id_settlement_locked'], $this->url(['id_settlement' => $r['id_settlement_locked']], 'AdminBxmpSettlements'), 'label label-default')
                : '<span class="text-muted">open</span>';
            if ($r['status'] === 'cancelled') {
                $r['_class'] = 'bxmp-row-muted';
            } elseif (abs($r['drift']) > 0.005) {
                $r['_class'] = $r['locked'] ? '' : 'bxmp-row-warning';
            }
        }

        return $rows;
    }

    protected function handleActions(): void
    {
        $this->maybeExport([
            'commissions' => function () {
                $cols = self::columns();
                unset($cols['status_html'], $cols['settlement_html']);
                $cols += ['id_vendor_order' => ['ID'], 'id_order' => ['ID order'], 'status' => ['Status'], 'commission_rate' => ['Rate %'],
                    'commission_fixed' => ['Fixed/line'], 'expected_payout' => ['Payout under current terms'], 'id_settlement_locked' => ['Locked by settlement']];

                return [$cols, $this->rows()];
            },
        ]);

        if (Tools::isSubmit('submitBxmpRecalc')) {
            if (!$this->access('edit')) {
                throw new RuntimeException('You do not have permission to edit.');
            }
            $ids = array_map('intval', (array) Tools::getValue('ids', []));
            if (!$ids) {
                $this->redirectWith('No vendor order selected.', false);
            }
            $changed = $this->commissions->recalculate($this->vendorId(), $ids);
            $this->redirectWith(sprintf('%d vendor order(s) recalculated with the current commission terms (draft settlements refreshed).', count($changed)));
        }
    }

    protected function renderPage(): string
    {
        if (Tools::getValue('sub') === 'recalc') {
            return $this->renderRecalc();
        }
        $pending = $this->commissions->pendingRecalculation();
        $byVendor = [];
        foreach ($pending as $o) {
            $byVendor[(int) $o['id_vendor']] ??= ['name' => $o['vendor_name'], 'count' => 0, 'delta' => 0.0, 'terms' => sprintf('%.2f %% + %.2f €', $o['commission_rate'], $o['commission_fixed'])];
            ++$byVendor[(int) $o['id_vendor']]['count'];
            $byVendor[(int) $o['id_vendor']]['delta'] += $o['drift'];
        }
        $html = '';
        if ($byVendor) {
            $html .= '<div class="alert alert-warning bxmp-recalc-notice"><p><strong>Commission terms changed.</strong> These vendors have unsettled orders whose commission differs from their current terms:</p><ul>';
            foreach ($byVendor as $id => $v) {
                $html .= sprintf('<li>%s (now %s): %d order(s), commission delta %+.2f € — %s</li>', htmlspecialchars($v['name']), $v['terms'], $v['count'], $v['delta'],
                    $this->link('review & recalculate', $this->url(['sub' => 'recalc', 'id_vendor' => $id]), 'btn btn-default btn-xs'));
            }
            $html .= '</ul></div>';
        }

        $html .= $this->filterBar(['selects' => [['label' => 'Status', 'name' => 'status', 'value' => (string) $this->status(),
            'options' => ['' => 'All'] + array_combine(BxmpVendorOrder::STATUSES, BxmpVendorOrder::STATUSES)]]]);
        $rows = $this->rows();
        $live = array_filter($rows, static fn ($r) => $r['status'] !== 'cancelled');
        $sum = static fn ($k) => round(array_sum(array_map(static fn ($r) => (float) $r[$k], $live)), 2);
        $html .= $this->table('Commission per vendor order', self::columns(), $rows, 'commissions', [
            'date_add' => 'Total (excl. cancelled)', 'total_products' => $sum('total_products'), 'total_shipping' => $sum('total_shipping'), 'total_paid' => $sum('total_paid'),
            'commission_amount' => $sum('commission_amount'), 'expected_commission' => $sum('expected_commission'), 'drift' => $sum('drift'), 'vendor_payout' => $sum('vendor_payout'),
        ], 'icon-money', 'No vendor orders in this period.');
        $html .= '<p class="text-muted">Commission = rate % of each line + fixed amount per line' . (Configuration::get('BXMP_COMMISSION_ON_SHIPPING') ? ' + rate % of shipping' : '')
            . ' (core CommissionCalculator). Orders inside an approved/paid settlement are locked. Manual corrections: '
            . $this->link('Commission adjustments', $this->url([], 'AdminBxmpAdjustments')) . '.</p>';

        return $html;
    }

    private function renderRecalc(): string
    {
        $idVendor = $this->vendorId();
        $rows = $this->commissions->pendingRecalculation($idVendor);
        foreach ($rows as &$r) {
            $r['check'] = '<input type="checkbox" name="ids[]" value="' . (int) $r['id_vendor_order'] . '" checked>';
            $r['terms'] = sprintf('%.2f %% + %.2f €', (float) $r['commission_rate'], (float) $r['commission_fixed']);
        }
        unset($r);
        $table = $this->table('Unsettled orders to recalculate', [
            'check' => ['', 'html'], 'date_add' => ['Date'], 'reference' => ['Vendor order'], 'vendor_name' => ['Vendor'], 'status' => ['Status'], 'terms' => ['Current terms'],
            'total_paid' => ['Gross', 'money'], 'commission_amount' => ['Stored commission', 'money'], 'expected_commission' => ['New commission', 'money'],
            'drift' => ['Delta', 'money'], 'vendor_payout' => ['Stored payout', 'money'], 'expected_payout' => ['New payout', 'money'],
        ], $rows, null, ['date_add' => 'Total', 'drift' => round(array_sum(array_column($rows, 'drift')), 2)], 'icon-refresh', 'Nothing to recalculate: every unsettled order matches the current terms.');

        return $this->fetch('recalc.tpl', [
            'table' => $table,
            'count' => count($rows),
            'action' => $this->url($idVendor ? ['id_vendor' => $idVendor] : []),
            'back' => $this->url(),
        ]);
    }
}

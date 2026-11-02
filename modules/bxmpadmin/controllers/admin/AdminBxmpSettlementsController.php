<?php
/**
 * Vendor settlements (scope module 7): generate per vendor + period with the core CommissionCalculator,
 * workflow draft → approved → paid, detail page with orders/adjustments and a scoped reconciliation.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpadmin/classes/BxmpAdminPageController.php';

use BrainX\MarketplaceAdmin\Domain\Period;
use BrainX\MarketplaceAdmin\Repository\FinanceRepository;
use BrainX\MarketplaceAdmin\Service\SettlementService;

class AdminBxmpSettlementsController extends BxmpAdminPageController
{
    private FinanceRepository $finance;
    private SettlementService $service;

    public function __construct()
    {
        parent::__construct();
        $this->finance = new FinanceRepository();
        $this->service = new SettlementService($this->finance);
        $this->meta_title = ['Settlements'];
    }

    public static function columns(): array
    {
        return [
            'id_settlement' => ['#', 'int'],
            'vendor_name' => ['Vendor'],
            'period_from' => ['From'],
            'period_to' => ['To'],
            'status_html' => ['Status', 'html'],
            'orders' => ['Orders', 'int'],
            'gross' => ['Gross', 'money'],
            'commission' => ['Commission', 'money'],
            'adjustments' => ['Adjustments', 'money'],
            'net_payout' => ['Net payout', 'money'],
            'check_html' => ['Live check', 'html'],
            'payout_account' => ['Payout account'],
            'date_add' => ['Created'],
            'actions' => ['', 'html'],
        ];
    }

    private function statusFilter(): ?string
    {
        $s = (string) Tools::getValue('status');

        return in_array($s, ['draft', 'approved', 'paid'], true) ? $s : null;
    }

    protected function extraExportParams(): array
    {
        return array_filter(['status' => $this->statusFilter(), 'id_settlement' => (int) Tools::getValue('id_settlement') ?: null]);
    }

    private function listRows(): array
    {
        $rows = $this->service->withComputed($this->finance->settlements(['id_vendor' => $this->vendorId(), 'status' => $this->statusFilter(), 'period' => $this->period()]));
        foreach ($rows as &$r) {
            $r['orders'] = $r['computed']['orders'];
            $r['status_html'] = $this->badge((string) $r['status']);
            $stale = abs((float) $r['computed']['net_payout'] - (float) $r['net_payout']) > 0.005
                || abs((float) $r['computed']['gross'] - (float) $r['gross']) > 0.005;
            $r['stale'] = $stale ? 'stale' : 'ok';
            $r['check_html'] = $stale
                ? '<span class="label label-' . ($r['status'] === 'draft' ? 'warning' : 'danger') . '" title="live net payout ' . sprintf('%.2f', $r['computed']['net_payout']) . '">differs</span>'
                : '<span class="label label-success">ok</span>';
            $r['actions'] = $this->actionsHtml($r);
            if ($stale && $r['status'] !== 'draft') {
                $r['_class'] = 'bxmp-row-error';
            }
        }

        return $rows;
    }

    private function actionsHtml(array $s): string
    {
        $id = (int) $s['id_settlement'];
        $h = $this->link('View', $this->url(['id_settlement' => $id]), 'btn btn-default btn-xs') . ' ';
        $btn = function (string $action, string $label, string $class, string $confirm = '') use ($id): string {
            return '<form method="post" class="bxmp-inline-form" action="' . htmlspecialchars($this->url(['id_settlement' => $id] + (Tools::getValue('id_settlement') ? [] : ['back' => 'list'])), ENT_QUOTES) . '">'
                . '<input type="hidden" name="settlement_action" value="' . $action . '"><input type="hidden" name="id_settlement" value="' . $id . '">'
                . '<button type="submit" name="submitBxmpSettlementAction" value="1" class="btn btn-xs ' . $class . '"' . ($confirm ? ' onclick="return confirm(\'' . htmlspecialchars($confirm, ENT_QUOTES) . '\')"' : '') . '>' . $label . '</button></form>';
        };
        switch ($s['status']) {
            case 'draft':
                $h .= $btn('refresh', 'Recompute', 'btn-default') . $btn('approved', 'Approve', 'btn-primary', 'Approve this settlement? Its vendor orders and adjustments get locked.')
                    . $btn('delete', 'Delete', 'btn-danger', 'Delete this draft settlement?');
                break;
            case 'approved':
                $h .= $btn('paid', 'Mark paid', 'btn-success', 'Mark this settlement as paid to the vendor?') . $btn('draft', 'Reopen', 'btn-default', 'Reopen as draft (unlocks orders)?');
                break;
        }

        return $h;
    }

    protected function handleActions(): void
    {
        $this->maybeExport([
            'settlements' => function () {
                $cols = self::columns();
                unset($cols['status_html'], $cols['check_html'], $cols['actions']);

                return [$cols + ['id_vendor' => ['ID vendor'], 'status' => ['Status'], 'stale' => ['Live check']], $this->listRows()];
            },
            'settlement_orders' => function () {
                $id = (int) Tools::getValue('id_settlement');
                $s = $this->finance->settlement($id) ?? throw new RuntimeException('Unknown settlement');
                $data = $this->service->compute((int) $s['id_vendor'], (string) $s['period_from'], (string) $s['period_to']);
                $rows = [];
                foreach ($data['orders'] as $o) {
                    $rows[] = ['type' => 'vendor_order', 'date' => $o['date_add'], 'reference' => $o['reference'], 'status' => $o['status'], 'gross' => (float) $o['total_paid'],
                        'commission' => (float) $o['commission_amount'], 'payout' => (float) $o['vendor_payout'], 'included' => $o['status'] === 'cancelled' ? 0 : 1, 'note' => $o['payment_transfer_ref']];
                }
                foreach ($data['adjustments'] as $a) {
                    $rows[] = ['type' => 'adjustment', 'date' => $a['date_add'], 'reference' => $a['vendor_order_reference'], 'status' => '', 'gross' => '', 'commission' => '',
                        'payout' => (float) $a['amount'], 'included' => 1, 'note' => $a['reason']];
                }
                $t = $data['totals'];
                $rows[] = ['type' => 'TOTAL', 'date' => $s['period_from'] . ' → ' . $s['period_to'], 'reference' => 'settlement #' . $id, 'status' => $s['status'],
                    'gross' => $t->gross, 'commission' => $t->commission, 'payout' => $t->netPayout(), 'included' => $t->ordersCount, 'note' => 'stored net payout ' . $s['net_payout']];

                return [['type' => 'Type', 'date' => 'Date', 'reference' => 'Reference', 'status' => 'Status', 'gross' => 'Gross', 'commission' => 'Commission',
                    'payout' => 'Payout / adjustment', 'included' => 'Included', 'note' => 'Transfer ref / reason'], $rows];
            },
        ]);

        if (Tools::isSubmit('submitBxmpGenerate')) {
            if (!$this->access('add')) {
                throw new RuntimeException('You do not have permission to add.');
            }
            $from = (string) Tools::getValue('gen_from');
            $to = (string) Tools::getValue('gen_to');
            $idVendor = (int) Tools::getValue('gen_vendor');
            $vendorIds = $idVendor ? [$idVendor] : array_map('intval', array_column($this->finance->vendors(), 'id_vendor'));
            $ok = [];
            $errors = [];
            foreach ($vendorIds as $v) {
                try {
                    $s = $this->service->generate($v, $from, $to);
                    $ok[] = '#' . $s->id;
                } catch (RuntimeException $e) {
                    $errors[] = (BxmpVendor::getNames()[$v] ?? '#' . $v) . ': ' . $e->getMessage();
                }
            }
            $msg = $ok ? sprintf('Draft settlement(s) %s generated for %s → %s.', implode(', ', $ok), $from, $to) : '';
            if ($errors) {
                $this->redirectWith(trim($msg . ' Errors: ' . implode('; ', $errors)), !$ok ? false : true, $idVendor && count($ok) === 1 ? ['id_settlement' => (int) substr($ok[0], 1)] : []);
            }
            $this->redirectWith($msg, true, count($ok) === 1 ? ['id_settlement' => (int) substr($ok[0], 1)] : []);
        }

        if (Tools::isSubmit('submitBxmpSettlementAction')) {
            if (!$this->access('edit')) {
                throw new RuntimeException('You do not have permission to edit.');
            }
            $id = (int) Tools::getValue('id_settlement');
            $action = (string) Tools::getValue('settlement_action');
            $back = Tools::getValue('back') === 'list' ? [] : ['id_settlement' => $id];
            try {
                if ($action === 'delete') {
                    $this->service->deleteDraft($id);
                    $this->redirectWith('Draft settlement #' . $id . ' deleted.');
                } elseif ($action === 'refresh') {
                    $s = new BxmpSettlement($id);
                    $this->service->generate((int) $s->id_vendor, (string) $s->period_from, (string) $s->period_to);
                    $this->redirectWith('Settlement #' . $id . ' recomputed.', true, $back);
                } else {
                    $s = $this->service->transition($id, $action);
                    $this->redirectWith(sprintf('Settlement #%d is now %s (net payout %.2f €).', $id, $s->status, (float) $s->net_payout), true, $back);
                }
            } catch (RuntimeException $e) {
                $this->redirectWith($e->getMessage(), false, $back);
            }
        }
    }

    protected function renderPage(): string
    {
        $id = (int) Tools::getValue('id_settlement');
        if ($id) {
            return $this->renderDetail($id);
        }
        $lastMonth = Period::fromRequest('last_month');
        $html = $this->fetch('settlement_generate.tpl', [
            'action' => $this->url(),
            'vendors' => BxmpVendor::getNames(),
            'from' => $lastMonth->from->format('Y-m-d'),
            'to' => $lastMonth->to->format('Y-m-d'),
            'month_from' => (new DateTimeImmutable('first day of this month'))->format('Y-m-d'),
            'today' => date('Y-m-d'),
        ]);
        $html .= $this->filterBar(['selects' => [['label' => 'Status', 'name' => 'status', 'value' => (string) $this->statusFilter(),
            'options' => ['' => 'All', 'draft' => 'draft', 'approved' => 'approved', 'paid' => 'paid']]]]);
        $rows = $this->listRows();
        $html .= $this->table('Settlements overlapping the period', self::columns(), $rows, 'settlements', [
            'vendor_name' => 'Total',
            'orders' => array_sum(array_column($rows, 'orders')),
            'gross' => round(array_sum(array_column($rows, 'gross')), 2),
            'commission' => round(array_sum(array_column($rows, 'commission')), 2),
            'adjustments' => round(array_sum(array_column($rows, 'adjustments')), 2),
            'net_payout' => round(array_sum(array_column($rows, 'net_payout')), 2),
        ], 'icon-file-text', 'No settlement for these filters. Generate one above.');
        $html .= '<p class="text-muted">Net payout = gross (vendor orders total paid, cancelled excluded) − commission + adjustments, computed by the core CommissionCalculator::settle(). '
            . '"Live check" recomputes from current data: a difference on an approved/paid settlement means data changed after approval (see Reconciliation).</p>';

        return $html;
    }

    private function renderDetail(int $id): string
    {
        $s = $this->finance->settlement($id);
        if (!$s) {
            throw new RuntimeException('Unknown settlement #' . $id);
        }
        $data = $this->service->compute((int) $s['id_vendor'], (string) $s['period_from'], (string) $s['period_to']);
        $t = $data['totals'];
        $period = new Period(new DateTimeImmutable((string) $s['period_from']), new DateTimeImmutable((string) $s['period_to']));
        $rec = $this->service->reconcile($period, (int) $s['id_vendor']);
        $issues = array_values(array_filter($rec['issues'], static fn ($i) => !isset($i['id_settlement']) || (int) $i['id_settlement'] === $id));

        $orders = $data['orders'];
        foreach ($orders as &$o) {
            $o['status_html'] = $this->badge((string) $o['status']);
            $o['included'] = $o['status'] !== 'cancelled';
            if ($o['status'] === 'cancelled') {
                $o['_class'] = 'bxmp-row-muted';
            } elseif (trim((string) $o['payment_transfer_ref']) === '') {
                $o['_class'] = 'bxmp-row-warning';
            }
        }
        unset($o);
        foreach ($issues as &$i) {
            $i['severity_html'] = $this->badge((string) $i['severity']);
            $i['_class'] = 'bxmp-row-' . $i['severity'];
        }
        unset($i);
        $live = array_filter($orders, static fn ($o) => $o['included']);
        $sum = static fn ($k) => round(array_sum(array_map(static fn ($o) => (float) $o[$k], $live)), 2);

        $html = $this->fetch('settlement_detail.tpl', [
            's' => $s,
            'status_html' => $this->badge((string) $s['status']),
            'actions' => $this->actionsHtml($s),
            'live' => ['gross' => $t->gross, 'commission' => $t->commission, 'adjustments' => $t->adjustments, 'net_payout' => $t->netPayout(), 'orders' => $t->ordersCount],
            'transferred' => round(array_sum(array_map(static fn ($o) => trim((string) $o['payment_transfer_ref']) !== '' ? (float) $o['vendor_payout'] : 0, $live)), 2),
            'back' => $this->url(),
            'export_url' => $this->url(['export' => 'settlement_orders', 'id_settlement' => $id]),
            'reconciliation_url' => $this->url(['period' => 'custom', 'date_from' => $s['period_from'], 'date_to' => $s['period_to'], 'id_vendor' => $s['id_vendor']], 'AdminBxmpReconciliation'),
        ]);
        $html .= $this->table('Vendor orders in the period', [
            'date_add' => ['Date'], 'reference' => ['Vendor order'], 'order_reference' => ['Order'], 'status_html' => ['Status', 'html'], 'included' => ['Included', 'bool'],
            'total_products' => ['Products', 'money'], 'total_shipping' => ['Shipping', 'money'], 'total_paid' => ['Gross', 'money'],
            'commission_amount' => ['Commission', 'money'], 'vendor_payout' => ['Payout', 'money'], 'payment_transfer_ref' => ['Transfer ref'],
        ], $orders, null, ['date_add' => 'Included total', 'total_products' => $sum('total_products'), 'total_shipping' => $sum('total_shipping'),
            'total_paid' => $sum('total_paid'), 'commission_amount' => $sum('commission_amount'), 'vendor_payout' => $sum('vendor_payout')], 'icon-shopping-cart');
        $html .= $this->table('Commission adjustments in the period', [
            'date_add' => ['Date'], 'vendor_order_reference' => ['Vendor order'], 'amount' => ['Amount', 'money'], 'reason' => ['Reason'],
        ], $data['adjustments'], null, ['date_add' => 'Total', 'amount' => $t->adjustments], 'icon-pencil', 'No adjustment.');
        $html .= $this->table('Reconciliation checks', [
            'severity_html' => ['Severity', 'html'], 'code' => ['Check'], 'reference' => ['Vendor order'], 'amount' => ['Amount', 'money'], 'message' => ['Detail'],
        ], $issues, null, [], 'icon-check', 'No issue: orders, payouts, commissions, adjustments and transfers reconcile.');

        return $html;
    }
}

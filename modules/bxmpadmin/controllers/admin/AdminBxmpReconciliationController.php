<?php
/**
 * Financial reconciliation: vendor orders gross vs payouts vs commissions vs adjustments vs payment transfer refs
 * vs PrestaShop parent orders vs settlements, with flagged mismatches (see ReconciliationChecker).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpadmin/classes/BxmpAdminPageController.php';

use BrainX\MarketplaceAdmin\Service\SettlementService;

class AdminBxmpReconciliationController extends BxmpAdminPageController
{
    private ?array $result = null;

    public function __construct()
    {
        parent::__construct();
        $this->meta_title = ['Reconciliation'];
    }

    public static function vendorColumns(): array
    {
        return [
            'vendor_name' => ['Vendor'],
            'orders' => ['Orders', 'int'],
            'cancelled' => ['Cancelled', 'int'],
            'gross' => ['Gross', 'money'],
            'commission' => ['Commission', 'money'],
            'payout' => ['Payouts', 'money'],
            'adjustments' => ['Adjustments', 'money'],
            'net_due' => ['Net due', 'money'],
            'transferred' => ['Transferred', 'money'],
            'untransferred' => ['Not transferred', 'money'],
            'settled' => ['Settled (approved/paid)', 'money'],
            'issues' => ['Issues', 'int'],
        ];
    }

    public static function issueColumns(): array
    {
        return [
            'severity_html' => ['Severity', 'html'],
            'code' => ['Check'],
            'vendor_name' => ['Vendor'],
            'reference' => ['Vendor order'],
            'id_order' => ['Order ID'],
            'amount' => ['Amount', 'money'],
            'message' => ['Detail'],
        ];
    }

    private function result(): array
    {
        if ($this->result === null) {
            $this->result = (new SettlementService())->reconcile($this->period(), $this->vendorId());
            foreach ($this->result['vendors'] as $id => &$v) {
                $v['id_vendor'] = $id;
                if ($v['issues'] > 0) {
                    $v['_class'] = 'bxmp-row-warning';
                }
            }
            unset($v);
            foreach ($this->result['issues'] as &$i) {
                $i['severity_html'] = $this->badge((string) $i['severity']);
                $i['_class'] = 'bxmp-row-' . $i['severity'];
                $i['reference'] ??= isset($i['id_settlement']) ? 'settlement #' . $i['id_settlement'] : '';
                $i['id_order'] ??= '';
            }
        }

        return $this->result;
    }

    protected function handleActions(): void
    {
        $this->maybeExport([
            'reconciliation' => fn () => [['id_vendor' => ['ID vendor']] + self::vendorColumns(), array_values($this->result()['vendors'])],
            'reconciliation_issues' => function () {
                $cols = self::issueColumns();
                unset($cols['severity_html']);

                return [['severity' => ['Severity']] + $cols + ['id_vendor_order' => ['ID vendor order'], 'id_settlement' => ['ID settlement']], $this->result()['issues']];
            },
        ]);
    }

    protected function renderPage(): string
    {
        $r = $this->result();
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($r['issues'] as $i) {
            ++$counts[$i['severity']];
        }
        $byCode = [];
        foreach ($r['issues'] as $i) {
            $byCode[$i['code']] = ($byCode[$i['code']] ?? 0) + 1;
        }
        $html = $this->filterBar();
        $html .= $this->fetch('reconciliation.tpl', ['t' => $r['totals'], 'counts' => $counts, 'by_code' => $byCode]);
        $html .= $this->table('Per-vendor reconciliation', self::vendorColumns(), array_values($r['vendors']), 'reconciliation',
            ['vendor_name' => 'Total'] + array_intersect_key($r['totals'], self::vendorColumns()), 'icon-exchange', 'No vendor activity in this period.');
        $html .= $this->table('Flagged mismatches', self::issueColumns(), $r['issues'], 'reconciliation_issues', [], 'icon-warning-sign',
            'No mismatch: every vendor order reconciles.');

        return $html;
    }
}

<?php
/**
 * Vendor portal — orders & shipments: own bxmp_vendor_order rows (filter by status / date / reference), detail with
 * lines and customer delivery address (from the parent PS order), workflow actions accept → ship (tracking +
 * carrier) → delivered / cancel, dispatching actionBxmpVendorOrderStatusUpdate. GET action=export streams a CSV of
 * the filtered own orders.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/classes/BxmpVendorPortalController.php';

use BrainX\MarketplaceVendor\Domain\OrderWorkflow;
use BrainX\MarketplaceVendor\Domain\RolePolicy;
use BrainX\MarketplaceVendor\Infrastructure\VendorOrderService;

class BxmpvendorportalOrdersModuleFrontController extends BxmpVendorPortalController
{
    protected string $portalPage = 'orders';
    protected string $permission = RolePolicy::ORDERS_VIEW;

    private const ERRORS = [
        'not_found' => 'Order not found.',
        'unknown_action' => 'Unknown action.',
        'invalid_transition' => 'This action is not possible in the current order status.',
        'invalid_tracking_number' => 'Please enter a valid tracking number (3-64 letters, digits, - _ . /).',
        'invalid_carrier' => 'Please enter the carrier name.',
        'concurrent_update' => 'The order was modified meanwhile — please retry.',
    ];

    private function filters(): array
    {
        return [
            'status' => in_array($s = $this->str('status', 16), BxmpVendorOrder::STATUSES, true) ? $s : '',
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f = $this->str('date_from', 10)) ? $f : '',
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $t = $this->str('date_to', 10)) ? $t : '',
            'q' => $this->str('q', 64),
        ];
    }

    protected function processPost(string $action): void
    {
        if (!isset(OrderWorkflow::ACTIONS[$action])) {
            parent::processPost($action);

            return;
        }
        if (!$this->requirePermission(RolePolicy::ORDERS_MANAGE)) {
            return;
        }
        $id = (int) Tools::getValue('id_vendor_order');
        $res = (new VendorOrderService())->apply($this->idVendor, $id, $action, [
            'tracking_number' => Tools::getValue('tracking_number'),
            'carrier_name' => Tools::getValue('carrier_name'),
        ], ['id_customer' => (int) $this->context->customer->id, 'vendor_name' => $this->membership['vendor_name']]);
        if ($res['error'] === 'not_found') {
            $this->notFound();

            return;
        }
        if ($res['ok']) {
            $this->success[] = sprintf('Order status changed: %s → %s.', $res['from'], $res['to']);
        } else {
            $this->errors[] = self::ERRORS[$res['error']] ?? $res['error'];
        }
        $this->back(['id' => $id]);
    }

    protected function processGet(string $action): void
    {
        if ($action !== 'export') {
            return;
        }
        if (!$this->requirePermission(RolePolicy::CSV_EXPORT_ORDERS)) {
            return;
        }
        $rows = (new VendorOrderService())->search($this->idVendor, $this->filters(), 5000, 0);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders-' . $this->vendorModel->code . '-' . date('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $cols = ['id_vendor_order', 'reference', 'id_order', 'date_add', 'status', 'items', 'total_products', 'total_shipping', 'total_paid', 'commission_amount', 'vendor_payout', 'carrier_name', 'tracking_number'];
        fputcsv($out, $cols, ';', '"', '');
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = (string) ($r[$c] ?? '');
                // neutralise spreadsheet formula injection
                $line[] = preg_match('/^[=+\-@\t\r]/', $v) && !is_numeric($v) ? "'" . $v : $v;
            }
            fputcsv($out, $line, ';', '"', '');
        }
        fclose($out);
        exit;
    }

    protected function renderPage(): void
    {
        $service = new VendorOrderService();
        if (Tools::getIsset('id')) {
            $order = $service->findOwn($this->idVendor, (int) Tools::getValue('id'));
            if ($order === null) {
                $this->notFound();
                $this->renderDenied();

                return;
            }
            $this->context->smarty->assign(['ord' => [
                'order' => $order,
                'parent' => $service->parentOrderInfo($order),
                'list_url' => $this->url('orders'),
                'can_manage' => RolePolicy::can($this->role, RolePolicy::ORDERS_MANAGE),
                'carriers' => ['BRT', 'GLS', 'DHL', 'UPS', 'SDA', 'Poste Italiane', 'TNT/FedEx'],
            ]]);
            $this->setTemplate('module:bxmpvendorportal/views/templates/front/order_detail.tpl');

            return;
        }
        $filters = $this->filters();
        $pg = $this->pagination($service->count($this->idVendor, $filters));
        $this->context->smarty->assign(['ord' => [
            'rows' => $service->search($this->idVendor, $filters, $pg['size'], $pg['offset']),
            'filters' => $filters,
            'pagination' => $this->withLinks($pg, array_filter($filters)),
            'base_url' => $this->url('orders'),
            'list_params' => array_filter($filters),
            'export_url' => $this->url('orders', ['action' => 'export'] + array_filter($filters)),
            'statuses' => BxmpVendorOrder::STATUSES,
        ]]);
        $this->setTemplate('module:bxmpvendorportal/views/templates/front/orders.tpl');
    }
}

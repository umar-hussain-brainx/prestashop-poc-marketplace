<?php
/**
 * Marketplace > Order export: download vendor orders as CSV (vendor / date range / status filters) and upload an
 * order status CSV (vendor_order_reference, status, tracking_number, carrier_name).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

use BrainX\Csv\ImportException;
use BrainX\Csv\ImportService;
use BrainX\Csv\OrderExportService;

class AdminBxmpOrderExportController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitBxmpOrderExport')) {
            $filters = [
                'date_from' => (string) Tools::getValue('date_from'),
                'date_to' => (string) Tools::getValue('date_to'),
                'status' => array_map('strval', (array) Tools::getValue('status', [])),
            ];
            $idVendor = (int) Tools::getValue('id_vendor');
            (new OrderExportService())->download($idVendor, $filters);
        }
        if (Tools::isSubmit('submitBxmpStatusImport')) {
            try {
                if (empty($_FILES['status_file']['tmp_name']) || (int) $_FILES['status_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new ImportException('Choose a CSV file.');
                }
                $svc = new ImportService();
                $id = $svc->enqueueUpload((int) Tools::getValue('id_vendor_status'), $_FILES['status_file']['tmp_name'], 'order_status', ['original_name' => (string) $_FILES['status_file']['name']]);
                $job = $svc->process($id, 30);
                $this->confirmations[] = sprintf('Order status job #%d %s: %d rows, %d applied, %d errors (%d status changes).', $id, $job['status'], $job['rows_total'], $job['rows_ok'], $job['rows_error'], (int) ($job['stats']['updated'] ?? 0));
            } catch (ImportException $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        return parent::postProcess();
    }

    public function initContent()
    {
        $p = _DB_PREFIX_;
        $counts = Db::getInstance()->executeS("SELECT v.id_vendor, v.name, vo.status, COUNT(vo.id_vendor_order) AS n
            FROM `{$p}bxmp_vendor` v LEFT JOIN `{$p}bxmp_vendor_order` vo ON vo.id_vendor = v.id_vendor
            GROUP BY v.id_vendor, v.name, vo.status ORDER BY v.name") ?: [];
        $summary = [];
        foreach ($counts as $c) {
            $summary[$c['name']][$c['status'] ?? '-'] = (int) $c['n'];
        }
        $this->context->smarty->assign([
            'bxmp_vendors' => BxmpVendor::getNames(),
            'bxmp_statuses' => BxmpVendorOrder::STATUSES,
            'bxmp_summary' => $summary,
            'bxmp_action' => self::$currentIndex . '&token=' . $this->token,
            'bxmp_date_from' => date('Y-m-01'),
            'bxmp_date_to' => date('Y-m-d'),
            'bxmp_template_url' => $this->context->link->getAdminLink('AdminBxmpImports') . '&downloadTemplate=order_status',
        ]);
        $this->content = $this->module->display(_PS_MODULE_DIR_ . 'bxmpcsv/bxmpcsv.php', 'views/templates/admin/order_export.tpl');
        parent::initContent();
    }
}

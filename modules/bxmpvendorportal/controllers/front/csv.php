<?php
/**
 * Vendor portal — CSV bridge to the bxmpcsv module (only when it is installed & enabled):
 * upload a catalog / offer CSV or an order-status CSV (queued + processed right away through
 * \BrainX\Csv\ImportService), list own import jobs, download own error reports, templates and the own-orders CSV
 * (\BrainX\Csv\OrderExportService). Every call passes the vendor id of the authenticated membership.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/classes/BxmpVendorPortalController.php';

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\MarketplaceVendor\Domain\RolePolicy;

class BxmpvendorportalCsvModuleFrontController extends BxmpVendorPortalController
{
    protected string $portalPage = 'csv';
    protected string $permission = RolePolicy::CSV_EXPORT_ORDERS;

    /** bxmpcsv enabled and its public services loadable. */
    public static function csvAvailable(): bool
    {
        if (!Module::isEnabled('bxmpcsv')) {
            return false;
        }
        $autoload = _PS_MODULE_DIR_ . 'bxmpcsv/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        return class_exists('\BrainX\Csv\ImportService') && class_exists('\BrainX\Csv\OrderExportService');
    }

    private function ownJob(int $idJob): ?array
    {
        $job = (new \BrainX\Csv\ImportService())->getJob($idJob);

        return ($job !== null && (int) $job['id_vendor'] === $this->idVendor) ? $job : null;
    }

    protected function processPost(string $action): void
    {
        if ($action !== 'upload') {
            parent::processPost($action);

            return;
        }
        if (!self::csvAvailable()) {
            $this->errors[] = 'CSV import is not available.';
            $this->back();

            return;
        }
        $type = Tools::getValue('type') === 'order_status' ? 'order_status' : 'offers';
        if (!$this->requirePermission($type === 'offers' ? RolePolicy::CSV_IMPORT : RolePolicy::ORDERS_MANAGE)) {
            return;
        }
        $file = $_FILES['csv'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->errors[] = 'Please choose a CSV file to upload.';
            $this->back();

            return;
        }
        try {
            $svc = new \BrainX\Csv\ImportService();
            $options = ['original_name' => (string) $file['name']];
            if ($type === 'offers' && Tools::getValue('full_sync')) {
                $options['full_sync'] = true;
            }
            $idJob = $svc->enqueueUpload($this->idVendor, (string) $file['tmp_name'], $type, $options);
            $job = $svc->process($idJob, 20);
            Logger::channel('vendor')->info('CSV uploaded from portal', ['id_vendor' => $this->idVendor, 'id_import_job' => $idJob, 'type' => $type]);
            $this->success[] = sprintf('Import job #%d: %s — %d row(s) OK, %d error(s).', $idJob, $job['status'] ?? 'queued', (int) ($job['rows_ok'] ?? 0), (int) ($job['rows_error'] ?? 0));
        } catch (Throwable $e) {
            $this->errors[] = 'Import refused: ' . $e->getMessage();
        }
        $this->back();
    }

    protected function processGet(string $action): void
    {
        if ($action === '' || !self::csvAvailable()) {
            return;
        }
        switch ($action) {
            case 'export_orders':
                if ($this->requirePermission(RolePolicy::CSV_EXPORT_ORDERS)) {
                    $filters = array_filter([
                        'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f = $this->str('date_from', 10)) ? $f : null,
                        'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $t = $this->str('date_to', 10)) ? $t : null,
                        'status' => in_array($s = $this->str('status', 16), BxmpVendorOrder::STATUSES, true) ? $s : null,
                    ]);
                    (new \BrainX\Csv\OrderExportService())->download($this->idVendor, $filters, 'orders-' . $this->vendorModel->code . '-' . date('Ymd-His') . '.csv');
                }
                break;
            case 'template':
                $type = Tools::getValue('type') === 'order_status' ? 'order_status' : 'offers';
                $this->sendCsv(\BrainX\Csv\ImportService::templateCsv($type), $type . '-template.csv');
                break;
            case 'errors':
                $job = $this->ownJob((int) Tools::getValue('id_job'));
                $path = $job ? (new \BrainX\Csv\ImportService())->errorReport((int) $job['id_import_job']) : null;
                if ($path === null) {
                    $this->notFound();

                    return;
                }
                $this->sendCsv((string) file_get_contents($path), 'import-' . (int) $job['id_import_job'] . '-report.csv');
                break;
        }
    }

    private function sendCsv(string $content, string $filename): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }

    protected function renderPage(): void
    {
        $available = self::csvAvailable();
        $jobs = [];
        $job = null;
        if ($available) {
            $svc = new \BrainX\Csv\ImportService();
            $jobs = $svc->listJobs($this->idVendor, 25);
            if (Tools::getIsset('job')) {
                $job = $this->ownJob((int) Tools::getValue('job'));
                if ($job === null) {
                    $this->notFound();
                    $this->renderDenied();

                    return;
                }
            }
        }
        $this->context->smarty->assign(['csv' => [
            'available' => $available,
            'jobs' => $jobs,
            'job' => $job,
            'base_url' => $this->url('csv'),
            'export_url' => $this->url('csv', ['action' => 'export_orders']),
            'fallback_export_url' => $this->url('orders', ['action' => 'export']),
            'statuses' => BxmpVendorOrder::STATUSES,
        ]]);
        $this->setTemplate('module:bxmpvendorportal/views/templates/front/csv.tpl');
    }
}

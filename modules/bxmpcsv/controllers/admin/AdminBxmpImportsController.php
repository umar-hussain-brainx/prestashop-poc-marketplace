<?php
/**
 * Marketplace > Imports: upload a vendor CSV (offers or order status), list import jobs (status / counters / log),
 * run the worker now, download the template and the per-row error report.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

use BrainX\Csv\Config;
use BrainX\Csv\ImportException;
use BrainX\Csv\ImportService;
use BrainX\Csv\Worker\ImportWorker;

class AdminBxmpImportsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_import_job';
        $this->className = 'BxmpImportJob';
        $this->identifier = 'id_import_job';
        $this->lang = false;
        $this->list_no_link = false;
        $this->_defaultOrderBy = 'id_import_job';
        $this->_defaultOrderWay = 'DESC';

        parent::__construct();

        $this->_select = 'COALESCE(v.name, \'(all vendors)\') AS vendor_name';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = a.id_vendor';
        $this->addRowAction('view');
        $this->addRowAction('delete');

        $statuses = ['queued' => 'queued', 'running' => 'running', 'done' => 'done', 'failed' => 'failed'];
        $this->fields_list = [
            'id_import_job' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'vendor_name' => ['title' => $this->trans('Vendor', [], 'Modules.Bxmpcsv.Admin'), 'filter_key' => 'v!name'],
            'type' => ['title' => $this->trans('Type', [], 'Admin.Global'), 'type' => 'select', 'list' => ['offers' => 'offers', 'order_status' => 'order_status'], 'filter_key' => 'a!type'],
            'source' => ['title' => $this->trans('Source', [], 'Modules.Bxmpcsv.Admin'), 'type' => 'select', 'list' => ['upload' => 'upload', 'url' => 'url', 'path' => 'path'], 'filter_key' => 'a!source'],
            'file_path' => ['title' => $this->trans('File', [], 'Modules.Bxmpcsv.Admin'), 'callback' => 'shortPath', 'search' => false],
            'status' => ['title' => $this->trans('Status', [], 'Admin.Global'), 'type' => 'select', 'list' => $statuses, 'filter_key' => 'a!status', 'callback' => 'statusBadge'],
            'rows_total' => ['title' => $this->trans('Rows', [], 'Modules.Bxmpcsv.Admin'), 'align' => 'center', 'search' => false],
            'rows_ok' => ['title' => 'OK', 'align' => 'center', 'search' => false],
            'rows_error' => ['title' => $this->trans('Errors', [], 'Admin.Global'), 'align' => 'center', 'search' => false],
            'date_add' => ['title' => $this->trans('Queued', [], 'Modules.Bxmpcsv.Admin'), 'type' => 'datetime'],
            'date_end' => ['title' => $this->trans('Finished', [], 'Modules.Bxmpcsv.Admin'), 'type' => 'datetime'],
        ];
    }

    public function shortPath($value): string
    {
        $v = (string) $value;

        return htmlspecialchars(preg_match('#^https?://#', $v) ? $v : basename($v), ENT_QUOTES, 'UTF-8');
    }

    public function statusBadge($value): string
    {
        $cls = ['queued' => 'badge-info', 'running' => 'badge-warning', 'done' => 'badge-success', 'failed' => 'badge-danger'][$value] ?? '';

        return '<span class="badge ' . $cls . '">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_btn['run_worker'] = [
            'href' => self::$currentIndex . '&runWorker=1&token=' . $this->token,
            'desc' => $this->trans('Run worker now', [], 'Modules.Bxmpcsv.Admin'),
            'icon' => 'process-icon-refresh',
        ];
        $this->page_header_toolbar_btn['template'] = [
            'href' => self::$currentIndex . '&downloadTemplate=offers&token=' . $this->token,
            'desc' => $this->trans('CSV template', [], 'Modules.Bxmpcsv.Admin'),
            'icon' => 'process-icon-download',
        ];
        parent::initPageHeaderToolbar();
        unset($this->page_header_toolbar_btn['new']);
    }

    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
    }

    public function postProcess()
    {
        if (Tools::getValue('downloadTemplate')) {
            $type = Tools::getValue('downloadTemplate') === 'order_status' ? 'order_status' : 'offers';
            $this->sendFile(ImportService::templateCsv($type), 'bxmp-' . str_replace('_', '-', $type) . '-template.csv');
        }
        if (Tools::getValue('downloadErrors')) {
            $path = (new ImportService())->errorReport((int) Tools::getValue('downloadErrors'));
            if ($path) {
                $this->sendFile((string) file_get_contents($path), 'import-' . (int) Tools::getValue('downloadErrors') . '-report.csv');
            }
            $this->errors[] = $this->trans('No report for this job.', [], 'Modules.Bxmpcsv.Admin');
        }
        if (Tools::getValue('runWorker')) {
            $this->runWorker((int) Tools::getValue('job') ?: null);
        }
        if (Tools::isSubmit('submitBxmpcsvUpload')) {
            $this->handleUpload();
        }

        return parent::postProcess();
    }

    private function runWorker(?int $idJob): void
    {
        $summary = (new ImportWorker())->run(max(10, Config::int('BXMPCSV_MAX_RUNTIME') ?: 50), $idJob, true);
        if ($summary['busy']) {
            $this->warnings[] = $this->trans('Another worker is running; try again in a moment.', [], 'Modules.Bxmpcsv.Admin');

            return;
        }
        $parts = [];
        foreach ($summary['jobs'] as $j) {
            $parts[] = '#' . $j['id_import_job'] . ' ' . $j['status'] . ' (' . $j['processed'] . ' rows)';
        }
        $this->confirmations[] = sprintf('Worker ran %.1f s — feeds enqueued: %d — jobs: %s', $summary['seconds'], count($summary['feeds_enqueued']), $parts ? implode(', ', $parts) : 'queue empty');
    }

    private function handleUpload(): void
    {
        $svc = new ImportService();
        $idVendor = (int) Tools::getValue('bxmp_vendor');
        $type = Tools::getValue('bxmp_type') === 'order_status' ? 'order_status' : 'offers';
        $options = [
            'full_sync' => (bool) Tools::getValue('bxmp_full_sync'),
            'auto_create' => (bool) Tools::getValue('bxmp_auto_create'),
        ];
        try {
            $url = trim((string) Tools::getValue('bxmp_url'));
            $path = trim((string) Tools::getValue('bxmp_path'));
            if (!empty($_FILES['bxmp_file']['tmp_name']) && (int) $_FILES['bxmp_file']['error'] === UPLOAD_ERR_OK) {
                $id = $svc->enqueueUpload($idVendor, $_FILES['bxmp_file']['tmp_name'], $type, $options + ['original_name' => (string) $_FILES['bxmp_file']['name']]);
            } elseif ($url !== '') {
                $id = $svc->enqueueUrl($idVendor, $url, $type, $options);
            } elseif ($path !== '') {
                $id = $svc->enqueuePath($idVendor, $path, $type, $options);
            } else {
                throw new ImportException('Choose a CSV file, a URL or a server path.');
            }
            if (Tools::getValue('bxmp_process_now')) {
                $job = $svc->process($id, max(10, Config::int('BXMPCSV_MAX_RUNTIME') ?: 50));
                $this->confirmations[] = sprintf('Job #%d %s: %d/%d rows, %d ok, %d errors.', $id, $job['status'], $job['processed'], $job['rows_total'], $job['rows_ok'], $job['rows_error']);
            } else {
                $this->confirmations[] = sprintf('Job #%d queued: it will be processed by the worker (cron) or with "Run worker now".', $id);
            }
        } catch (ImportException $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    private function sendFile(string $content, string $name): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    public function renderList()
    {
        $vendors = BxmpVendor::getNames();
        $this->context->smarty->assign([
            'bxmp_vendors' => $vendors,
            'bxmp_action' => self::$currentIndex . '&token=' . $this->token,
            'bxmp_template_url' => self::$currentIndex . '&downloadTemplate=offers&token=' . $this->token,
            'bxmp_status_template_url' => self::$currentIndex . '&downloadTemplate=order_status&token=' . $this->token,
            'bxmp_full_sync' => Config::bool('BXMPCSV_FULL_SYNC'),
            'bxmp_auto_create' => Config::bool('BXMPCSV_AUTO_CREATE'),
            'bxmp_feeds_dir' => Config::feedsDir(),
            'bxmp_cron_url' => Config::cronUrl(),
            'bxmp_queue' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "bxmp_import_job` WHERE status IN ('queued','running')"),
            'bxmp_pending_exceptions' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "bxmp_match_exception` WHERE status = 'pending'"),
            'bxmp_exceptions_url' => $this->context->link->getAdminLink('AdminBxmpMatchExceptions'),
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . 'bxmpcsv/bxmpcsv.php', 'views/templates/admin/imports_upload.tpl') . parent::renderList();
    }

    public function renderView()
    {
        $id = (int) Tools::getValue('id_import_job');
        $job = (new ImportService())->getJob($id);
        if (!$job) {
            $this->errors[] = $this->trans('Job not found.', [], 'Modules.Bxmpcsv.Admin');

            return '';
        }
        $this->context->smarty->assign([
            'job' => $job,
            'back_url' => self::$currentIndex . '&token=' . $this->token,
            'errors_url' => self::$currentIndex . '&downloadErrors=' . $id . '&token=' . $this->token,
            'run_url' => self::$currentIndex . '&runWorker=1&job=' . $id . '&token=' . $this->token,
            'exceptions_url' => $this->context->link->getAdminLink('AdminBxmpMatchExceptions') . '&bxmp_match_exceptionFilter_a!id_import_job=' . $id . '&submitFilterbxmp_match_exception=1',
            'exception_count' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_match_exception` WHERE id_import_job = ' . $id),
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . 'bxmpcsv/bxmpcsv.php', 'views/templates/admin/job_view.tpl');
    }

    public function processDelete()
    {
        $id = (int) Tools::getValue('id_import_job');
        $res = parent::processDelete();
        if ($res && $id) {
            foreach ([$id . '.json', $id . '.csv', $id . '.errors.csv'] as $f) {
                @unlink(Config::jobsDir() . '/' . $f);
            }
        }

        return $res;
    }
}

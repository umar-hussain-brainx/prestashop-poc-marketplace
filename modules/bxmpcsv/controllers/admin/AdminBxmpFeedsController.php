<?php
/**
 * Marketplace > Feeds: scheduled vendor feeds (bxmp_feed: URL or server path + interval in minutes).
 * The cron / CLI worker enqueues due feeds automatically; "Run now" enqueues and processes one immediately.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

use BrainX\Csv\Config;
use BrainX\Csv\FeedScheduler;
use BrainX\Csv\ImportService;

class AdminBxmpFeedsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_feed';
        $this->className = 'BxmpFeed';
        $this->identifier = 'id_feed';
        $this->lang = false;
        $this->_defaultOrderBy = 'id_feed';

        parent::__construct();

        $this->_select = 'v.name AS vendor_name, DATE_ADD(a.last_run, INTERVAL GREATEST(a.schedule_minutes, 1) MINUTE) AS next_run,
            (SELECT j.status FROM `' . _DB_PREFIX_ . 'bxmp_import_job` j WHERE j.id_vendor = a.id_vendor AND j.source IN (\'url\',\'path\')
                AND (j.file_path = a.url_or_path OR j.file_path LIKE CONCAT(\'%/\', a.url_or_path)) ORDER BY j.id_import_job DESC LIMIT 1) AS last_job_status';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = a.id_vendor';
        $this->addRowAction('runnow');
        $this->addRowAction('edit');
        $this->addRowAction('delete');

        $this->fields_list = [
            'id_feed' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'vendor_name' => ['title' => $this->trans('Vendor', [], 'Modules.Bxmpcsv.Admin'), 'filter_key' => 'v!name'],
            'url_or_path' => ['title' => $this->trans('URL or path', [], 'Modules.Bxmpcsv.Admin')],
            'schedule_minutes' => ['title' => $this->trans('Every (min)', [], 'Modules.Bxmpcsv.Admin'), 'align' => 'center', 'class' => 'fixed-width-sm'],
            'last_run' => ['title' => $this->trans('Last run', [], 'Modules.Bxmpcsv.Admin'), 'type' => 'datetime'],
            'next_run' => ['title' => $this->trans('Next run', [], 'Modules.Bxmpcsv.Admin'), 'type' => 'datetime', 'search' => false],
            'last_job_status' => ['title' => $this->trans('Last job', [], 'Modules.Bxmpcsv.Admin'), 'search' => false],
            'active' => ['title' => $this->trans('Active', [], 'Admin.Global'), 'type' => 'bool', 'active' => 'status', 'align' => 'center'],
        ];
    }

    public function displayRunnowLink($token, $id, $name = null)
    {
        $href = self::$currentIndex . '&runFeed=' . (int) $id . '&token=' . ($token ?: $this->token);

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="btn btn-default" title="Run now"><i class="icon-play"></i> ' . $this->trans('Run now', [], 'Modules.Bxmpcsv.Admin') . '</a>';
    }

    public function postProcess()
    {
        if ($idFeed = (int) Tools::getValue('runFeed')) {
            try {
                $idJob = (new FeedScheduler())->enqueue($idFeed);
                if (!$idJob) {
                    $this->warnings[] = $this->trans('A job of this feed is already queued or running.', [], 'Modules.Bxmpcsv.Admin');
                } else {
                    $job = (new ImportService())->process($idJob, max(10, Config::int('BXMPCSV_MAX_RUNTIME') ?: 50));
                    $this->confirmations[] = sprintf('Feed #%d → job #%d %s: %d/%d rows, %d ok, %d errors.', $idFeed, $idJob, $job['status'], $job['processed'], $job['rows_total'], $job['rows_ok'], $job['rows_error']);
                }
            } catch (Throwable $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        return parent::postProcess();
    }

    public function renderForm()
    {
        $vendors = [];
        foreach (BxmpVendor::getNames() as $id => $name) {
            $vendors[] = ['id' => $id, 'name' => $name];
        }
        $this->fields_form = [
            'legend' => ['title' => $this->trans('Scheduled feed', [], 'Modules.Bxmpcsv.Admin'), 'icon' => 'icon-time'],
            'input' => [
                ['type' => 'select', 'label' => $this->trans('Vendor', [], 'Modules.Bxmpcsv.Admin'), 'name' => 'id_vendor', 'required' => true, 'options' => ['query' => $vendors, 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'label' => $this->trans('URL or server path', [], 'Modules.Bxmpcsv.Admin'), 'name' => 'url_or_path', 'required' => true,
                    'desc' => sprintf('http(s):// URL, or a file path relative to %s (other directories must be allowed in the module settings).', Config::feedsDir())],
                ['type' => 'text', 'label' => $this->trans('Interval (minutes)', [], 'Modules.Bxmpcsv.Admin'), 'name' => 'schedule_minutes', 'class' => 'fixed-width-sm', 'required' => true],
                ['type' => 'switch', 'label' => $this->trans('Active', [], 'Admin.Global'), 'name' => 'active', 'is_bool' => true,
                    'values' => [['id' => 'active_on', 'value' => 1, 'label' => 'Yes'], ['id' => 'active_off', 'value' => 0, 'label' => 'No']]],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ];
        if (!$this->object->id && !Tools::isSubmit('submitAddbxmp_feed')) {
            $this->fields_value = ['schedule_minutes' => 60, 'active' => 1];
        }

        return parent::renderForm();
    }

    public function processSave()
    {
        $src = trim((string) Tools::getValue('url_or_path'));
        $_POST['url_or_path'] = $src;
        if (!preg_match('#^https?://#i', $src)) {
            try {
                ImportService::resolvePath($src);
            } catch (Throwable $e) {
                $this->errors[] = $e->getMessage();

                return $this->redisplayForm();
            }
        } elseif (filter_var($src, FILTER_VALIDATE_URL) === false) {
            $this->errors[] = $this->trans('Invalid URL.', [], 'Modules.Bxmpcsv.Admin');

            return $this->redisplayForm();
        }
        if ((int) Tools::getValue('schedule_minutes') < 1) {
            $this->errors[] = $this->trans('The interval must be at least 1 minute.', [], 'Modules.Bxmpcsv.Admin');

            return $this->redisplayForm();
        }

        return parent::processSave();
    }

    /** Keep the submitted form on screen after a validation error. */
    private function redisplayForm(): bool
    {
        $this->display = (int) Tools::getValue('id_feed') ? 'edit' : 'add';

        return false;
    }
}

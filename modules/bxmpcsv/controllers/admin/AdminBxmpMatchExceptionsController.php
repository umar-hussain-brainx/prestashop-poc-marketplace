<?php
/**
 * Marketplace > Matching exceptions: review queue of vendor rows the matching engine could not match safely.
 * Actions: link to a candidate (or any) master product / combination, create a new master product, retry the
 * matching, reject. Linking / creating replays the stored row and writes the vendor offer immediately.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

use BrainX\Csv\Service\MatchExceptionService;

class AdminBxmpMatchExceptionsController extends ModuleAdminController
{
    private MatchExceptionService $service;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_match_exception';
        $this->className = 'BxmpMatchException';
        $this->identifier = 'id_match_exception';
        $this->lang = false;
        $this->_defaultOrderBy = 'id_match_exception';
        $this->_defaultOrderWay = 'DESC';
        $this->service = new MatchExceptionService();

        parent::__construct();

        $this->_select = 'v.name AS vendor_name';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = a.id_vendor';
        $this->addRowAction('view');
        $this->addRowAction('delete');
        $this->bulk_actions = [
            'reject' => ['text' => $this->trans('Reject selected', [], 'Modules.Bxmpcsv.Admin'), 'icon' => 'icon-ban', 'confirm' => 'Reject the selected exceptions?'],
            'retry' => ['text' => $this->trans('Retry matching', [], 'Modules.Bxmpcsv.Admin'), 'icon' => 'icon-refresh'],
            'delete' => ['text' => $this->trans('Delete selected', [], 'Admin.Actions'), 'icon' => 'icon-trash', 'confirm' => 'Delete?'],
        ];
        $this->fields_list = [
            'id_match_exception' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'vendor_name' => ['title' => $this->trans('Vendor', [], 'Modules.Bxmpcsv.Admin'), 'filter_key' => 'v!name'],
            'id_import_job' => ['title' => $this->trans('Job', [], 'Modules.Bxmpcsv.Admin'), 'class' => 'fixed-width-xs', 'filter_key' => 'a!id_import_job'],
            'vendor_sku' => ['title' => $this->trans('Vendor SKU', [], 'Modules.Bxmpcsv.Admin')],
            'ean13' => ['title' => 'EAN'],
            'brand' => ['title' => $this->trans('Brand', [], 'Admin.Global')],
            'title' => ['title' => $this->trans('Title', [], 'Admin.Global')],
            'reason' => ['title' => $this->trans('Reason', [], 'Modules.Bxmpcsv.Admin')],
            'status' => ['title' => $this->trans('Status', [], 'Admin.Global'), 'type' => 'select', 'list' => ['pending' => 'pending', 'resolved' => 'resolved', 'rejected' => 'rejected'], 'filter_key' => 'a!status', 'callback' => 'statusBadge'],
            'id_product_resolved' => ['title' => $this->trans('Product', [], 'Admin.Global'), 'class' => 'fixed-width-xs'],
            'date_upd' => ['title' => $this->trans('Updated', [], 'Admin.Global'), 'type' => 'datetime'],
        ];
    }

    public function statusBadge($value): string
    {
        $cls = ['pending' => 'badge-warning', 'resolved' => 'badge-success', 'rejected' => 'badge-danger'][$value] ?? '';

        return '<span class="badge ' . $cls . '">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
    }

    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();
        unset($this->page_header_toolbar_btn['new_bxmp_match_exception']);
    }

    public function postProcess()
    {
        $id = (int) Tools::getValue('id_match_exception');
        try {
            if (Tools::isSubmit('bxmpLink')) {
                $choice = (string) Tools::getValue('bxmp_candidate', '');
                $manual = (int) Tools::getValue('bxmp_manual_product');
                if ($manual > 0) {
                    [$idP, $idA] = [$manual, (int) Tools::getValue('bxmp_manual_attribute')];
                } elseif (preg_match('/^(\d+)-(\d+)$/', $choice, $m)) {
                    [$idP, $idA] = [(int) $m[1], (int) $m[2]];
                } else {
                    throw new InvalidArgumentException('Select a candidate or enter a product id.');
                }
                $o = $this->service->link($id, $idP, $idA);
                $this->redirectWithMessage(sprintf('Exception #%d linked to product #%d%s — offer #%d written.', $id, $idP, $idA ? ' / combination #' . $idA : '', $o->idOffer));
            } elseif (Tools::isSubmit('bxmpCreate')) {
                $o = $this->service->createProduct($id);
                $this->redirectWithMessage(sprintf('Exception #%d: master product #%d created — offer #%d written.', $id, $o->idProduct, $o->idOffer));
            } elseif (Tools::isSubmit('bxmpRetry')) {
                $o = $this->service->retry($id);
                $this->redirectWithMessage(sprintf('Exception #%d retried: %s %s', $id, $o->status, $o->message), !$o->isOk());
            } elseif (Tools::isSubmit('bxmpReject')) {
                $this->service->reject($id);
                $this->redirectWithMessage(sprintf('Exception #%d rejected: later imports skip this vendor SKU.', $id));
            }
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
            $this->display = 'view';

            return false;
        }

        return parent::postProcess();
    }

    private function redirectWithMessage(string $message, bool $stay = false): void
    {
        $this->confirmations[] = $message;
        if (!$stay) {
            $this->display = 'list';
        } else {
            $this->display = 'view';
        }
    }

    protected function processBulkReject()
    {
        $n = 0;
        foreach ((array) $this->boxes as $id) {
            try {
                $this->service->reject((int) $id);
                ++$n;
            } catch (Throwable $e) {
                $this->errors[] = $e->getMessage();
            }
        }
        $this->confirmations[] = $n . ' exception(s) rejected.';
    }

    protected function processBulkRetry()
    {
        $res = [];
        foreach ((array) $this->boxes as $id) {
            try {
                $res[] = '#' . (int) $id . ' ' . $this->service->retry((int) $id)->status;
            } catch (Throwable $e) {
                $this->errors[] = $e->getMessage();
            }
        }
        $this->confirmations[] = 'Retried: ' . implode(', ', $res);
    }

    public function renderView()
    {
        $id = (int) Tools::getValue('id_match_exception');
        $ex = $this->service->find($id);
        if (!$ex) {
            $this->errors[] = 'Exception not found.';

            return '';
        }
        $row = (array) ($ex['payload_data']['row'] ?? []);
        $display = [];
        foreach ($row as $k => $v) {
            $display[$k] = is_array($v) ? (array_is_list($v) ? implode(' | ', $v) : implode(' | ', array_map(static fn ($a, $b) => $a . ':' . $b, array_keys($v), $v))) : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        }
        if (isset($display['description']) && mb_strlen($display['description']) > 300) {
            $display['description'] = mb_substr(strip_tags($display['description']), 0, 300) . '…';
        }
        $candidates = $this->service->candidates($id);
        foreach ($candidates as &$c) {
            $c['admin_url'] = $this->context->link->getAdminLink('AdminProducts', true, ['id_product' => (int) $c['id_product']]);
        }
        unset($c);
        $match = (array) ($ex['payload_data']['match'] ?? []);
        $this->context->smarty->assign([
            'ex' => $ex,
            'row' => $display,
            'match' => ['status' => $match['status'] ?? '', 'reason' => $match['reason'] ?? '', 'method' => $match['method'] ?? '', 'score' => $match['score'] ?? ''],
            'line' => (int) ($ex['payload_data']['line'] ?? 0),
            'candidates' => $candidates,
            'action_url' => self::$currentIndex . '&id_match_exception=' . $id . '&viewbxmp_match_exception&token=' . $this->token,
            'back_url' => self::$currentIndex . '&token=' . $this->token,
            'job_url' => $ex['id_import_job'] ? $this->context->link->getAdminLink('AdminBxmpImports') . '&id_import_job=' . (int) $ex['id_import_job'] . '&viewbxmp_import_job' : null,
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . 'bxmpcsv/bxmpcsv.php', 'views/templates/admin/exception_view.tpl');
    }
}

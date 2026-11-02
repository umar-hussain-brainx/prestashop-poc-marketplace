<?php
/**
 * CRUD of manual commission adjustments (bxmp_commission_adjustment) with a mandatory reason.
 * amount > 0 = credit to the vendor (reduces marketplace revenue), amount < 0 = deduction.
 * Adjustments dated inside an approved/paid settlement are locked; draft settlements are refreshed on every change.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpadmin/vendor/autoload.php';

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\MarketplaceAdmin\Repository\FinanceRepository;
use BrainX\MarketplaceAdmin\Service\SettlementService;

class AdminBxmpAdjustmentsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_commission_adjustment';
        $this->className = 'BxmpCommissionAdjustment';
        $this->identifier = 'id_adjustment';
        $this->lang = false;
        $this->allow_export = true;
        $this->_defaultOrderBy = 'date_add';
        $this->_defaultOrderWay = 'DESC';

        parent::__construct();
        $this->meta_title = 'Commission adjustments';

        $this->_select = 'v.name AS vendor_name, vo.reference AS vendor_order_reference,
            (SELECT s.id_settlement FROM `' . _DB_PREFIX_ . 'bxmp_settlement` s WHERE s.id_vendor = a.id_vendor AND s.status IN (\'approved\', \'paid\')
             AND DATE(a.date_add) BETWEEN s.period_from AND s.period_to LIMIT 1) AS locked_by';
        $this->_join = 'INNER JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = a.id_vendor
            LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor_order` vo ON vo.id_vendor_order = a.id_vendor_order';

        $this->addRowAction('edit');
        $this->addRowAction('delete');

        $this->fields_list = [
            'id_adjustment' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'date_add' => ['title' => 'Date', 'type' => 'datetime', 'filter_key' => 'a!date_add'],
            'vendor_name' => ['title' => 'Vendor', 'filter_key' => 'v!name'],
            'vendor_order_reference' => ['title' => 'Vendor order', 'filter_key' => 'vo!reference'],
            'amount' => ['title' => 'Amount (credit +)', 'type' => 'price', 'align' => 'right', 'filter_key' => 'a!amount'],
            'reason' => ['title' => 'Reason', 'filter_key' => 'a!reason'],
            'locked_by' => ['title' => 'Locked by settlement', 'search' => false, 'align' => 'center'],
        ];
    }

    public function initPageHeaderToolbar()
    {
        if (empty($this->display)) {
            $this->page_header_toolbar_btn['new_adjustment'] = [
                'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
                'desc' => 'Add adjustment',
                'icon' => 'process-icon-new',
            ];
        }
        parent::initPageHeaderToolbar();
    }

    public function renderForm()
    {
        $vendors = array_map(static fn ($v) => ['id' => (int) $v['id_vendor'], 'name' => $v['name']], (new FinanceRepository())->vendors());
        $orders = [['id' => 0, 'name' => '— none (period-level adjustment) —']];
        foreach (Db::getInstance()->executeS('SELECT vo.id_vendor_order, vo.reference, vo.total_paid, vo.date_add, v.name FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` vo
            INNER JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = vo.id_vendor ORDER BY vo.date_add DESC LIMIT 500') ?: [] as $o) {
            $orders[] = ['id' => (int) $o['id_vendor_order'], 'name' => sprintf('%s — %s — %.2f € — %s', $o['reference'] ?: '#' . $o['id_vendor_order'], $o['name'], $o['total_paid'], substr((string) $o['date_add'], 0, 10))];
        }
        $this->fields_form = [
            'legend' => ['title' => 'Commission adjustment', 'icon' => 'icon-money'],
            'input' => [
                ['type' => 'select', 'label' => 'Vendor', 'name' => 'id_vendor', 'required' => true, 'options' => ['query' => $vendors, 'id' => 'id', 'name' => 'name']],
                ['type' => 'select', 'label' => 'Vendor order (optional)', 'name' => 'id_vendor_order', 'options' => ['query' => $orders, 'id' => 'id', 'name' => 'name'],
                    'desc' => 'Must belong to the selected vendor.'],
                ['type' => 'text', 'label' => 'Amount', 'name' => 'amount', 'required' => true, 'prefix' => '€', 'class' => 'fixed-width-md',
                    'desc' => 'Positive = credit to the vendor (e.g. commission refund, goodwill); negative = deduction (e.g. penalty, return fee). Tax excluded.'],
                ['type' => 'text', 'label' => 'Reason', 'name' => 'reason', 'required' => true, 'maxlength' => 255],
            ],
            'submit' => ['title' => 'Save'],
        ];

        return parent::renderForm();
    }

    private function assertNotLocked(?BxmpCommissionAdjustment $adj): bool
    {
        if ($adj && $adj->id && (new FinanceRepository())->isLocked((int) $adj->id_vendor, (string) $adj->date_add)) {
            $this->errors[] = 'This adjustment belongs to an approved/paid settlement and can no longer be changed. Create a new adjustment instead.';

            return false;
        }

        return true;
    }

    public function processSave()
    {
        $idVendor = (int) Tools::getValue('id_vendor');
        $amount = (float) str_replace(',', '.', (string) Tools::getValue('amount'));
        $reason = trim((string) Tools::getValue('reason'));
        $idVo = (int) Tools::getValue('id_vendor_order');
        if (!$idVendor || !(new BxmpVendor($idVendor))->id) {
            $this->errors[] = 'Select a vendor.';
        }
        if (abs($amount) < 0.005) {
            $this->errors[] = 'Amount must not be zero.';
        }
        if ($reason === '' || !Validate::isGenericName($reason)) {
            $this->errors[] = 'A valid reason is required.';
        }
        if ($idVo && (int) (new BxmpVendorOrder($idVo))->id_vendor !== $idVendor) {
            $this->errors[] = 'The vendor order does not belong to this vendor.';
        }
        $existing = (int) Tools::getValue('id_adjustment') ? new BxmpCommissionAdjustment((int) Tools::getValue('id_adjustment')) : null;
        if ($this->errors || !$this->assertNotLocked($existing)) {
            $this->display = 'edit';

            return false;
        }
        $_POST['amount'] = $amount;
        $_POST['id_vendor_order'] = $idVo ?: null;
        $res = parent::processSave();
        if ($res && $this->object) {
            Logger::channel('commission')->info('Commission adjustment saved', ['id_adjustment' => (int) $this->object->id, 'id_vendor' => $idVendor, 'amount' => $amount, 'reason' => $reason]);
            (new SettlementService())->refreshDrafts($idVendor);
            if ($existing && (int) $existing->id_vendor !== $idVendor) {
                (new SettlementService())->refreshDrafts((int) $existing->id_vendor);
            }
        }

        return $res;
    }

    public function processDelete()
    {
        $adj = $this->loadObject();
        if (!$adj || !$this->assertNotLocked($adj)) {
            return false;
        }
        $idVendor = (int) $adj->id_vendor;
        $res = parent::processDelete();
        if ($res) {
            Logger::channel('commission')->info('Commission adjustment deleted', ['id_adjustment' => (int) $adj->id, 'id_vendor' => $idVendor, 'amount' => (float) $adj->amount]);
            (new SettlementService())->refreshDrafts($idVendor);
        }

        return $res;
    }
}

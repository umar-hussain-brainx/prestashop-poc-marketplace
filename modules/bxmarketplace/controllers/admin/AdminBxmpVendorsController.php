<?php
/**
 * Back office CRUD for marketplace vendors (bxmp_vendor) incl. commercial conditions.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

class AdminBxmpVendorsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_vendor';
        $this->className = 'BxmpVendor';
        $this->identifier = 'id_vendor';
        $this->lang = false;
        $this->allow_export = true;
        $this->_defaultOrderBy = 'name';
        $this->_defaultOrderWay = 'ASC';

        parent::__construct();

        $this->_select = '(SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_offer` o WHERE o.id_vendor = a.id_vendor) AS offers_count,
            (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_vendor_user` u WHERE u.id_vendor = a.id_vendor) AS users_count';

        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->bulk_actions = [
            'delete' => ['text' => $this->trans('Delete selected', [], 'Admin.Actions'), 'confirm' => $this->trans('Delete selected vendors and their offers?', [], 'Modules.Bxmarketplace.Admin'), 'icon' => 'icon-trash'],
        ];

        $this->fields_list = [
            'id_vendor' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'name' => ['title' => $this->trans('Name', [], 'Admin.Global')],
            'code' => ['title' => $this->trans('Code', [], 'Modules.Bxmarketplace.Admin')],
            'email' => ['title' => $this->trans('Email', [], 'Admin.Global')],
            'company' => ['title' => $this->trans('Company', [], 'Admin.Global')],
            'commission_rate' => ['title' => $this->trans('Commission %', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'float', 'suffix' => '%', 'align' => 'right'],
            'commission_fixed' => ['title' => $this->trans('Fixed / line', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'price', 'align' => 'right'],
            'offers_count' => ['title' => $this->trans('Offers', [], 'Modules.Bxmarketplace.Admin'), 'search' => false, 'align' => 'center'],
            'users_count' => ['title' => $this->trans('Users', [], 'Modules.Bxmarketplace.Admin'), 'search' => false, 'align' => 'center'],
            'active' => ['title' => $this->trans('Active', [], 'Admin.Global'), 'type' => 'bool', 'active' => 'status', 'align' => 'center', 'class' => 'fixed-width-sm'],
        ];
    }

    public function initPageHeaderToolbar()
    {
        if (empty($this->display)) {
            $this->page_header_toolbar_btn['new_vendor'] = [
                'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
                'desc' => $this->trans('Add new vendor', [], 'Modules.Bxmarketplace.Admin'),
                'icon' => 'process-icon-new',
            ];
        }
        parent::initPageHeaderToolbar();
    }

    public function renderForm()
    {
        $this->fields_form = [
            'legend' => ['title' => $this->trans('Vendor', [], 'Modules.Bxmarketplace.Admin'), 'icon' => 'icon-truck'],
            'input' => [
                ['type' => 'text', 'label' => $this->trans('Name', [], 'Admin.Global'), 'name' => 'name', 'required' => true, 'maxlength' => 128],
                ['type' => 'text', 'label' => $this->trans('Code', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'code', 'maxlength' => 64,
                    'desc' => $this->trans('Unique slug (lowercase, digits, dashes). Generated from the name when empty.', [], 'Modules.Bxmarketplace.Admin')],
                ['type' => 'text', 'label' => $this->trans('Email', [], 'Admin.Global'), 'name' => 'email'],
                ['type' => 'text', 'label' => $this->trans('Company', [], 'Admin.Global'), 'name' => 'company'],
                ['type' => 'text', 'label' => $this->trans('VAT number', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'vat_number'],
                ['type' => 'text', 'label' => $this->trans('Commission rate', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'commission_rate', 'suffix' => '%', 'class' => 'fixed-width-sm',
                    'desc' => $this->trans('Percentage of each order line (tax excl.).', [], 'Modules.Bxmarketplace.Admin')],
                ['type' => 'text', 'label' => $this->trans('Fixed commission per order line', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'commission_fixed', 'prefix' => '€', 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => $this->trans('Payout account', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'payout_account',
                    'desc' => $this->trans('Mangopay wallet id or IBAN reference used for payment allocation.', [], 'Modules.Bxmarketplace.Admin')],
                ['type' => 'switch', 'label' => $this->trans('Active', [], 'Admin.Global'), 'name' => 'active', 'is_bool' => true,
                    'values' => [['id' => 'active_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'active_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ];

        $html = parent::renderForm();
        $obj = $this->loadObject(true);
        if ($obj && $obj->id) {
            $html .= $this->renderVendorSummary((int) $obj->id);
        }

        return $html;
    }

    private function renderVendorSummary(int $idVendor): string
    {
        $users = (new BrainX\Marketplace\Infrastructure\Repository\VendorRepository())->users($idVendor);
        $rules = BxmpShippingRule::getForVendor($idVendor);
        $h = '<div class="panel"><div class="panel-heading">' . $this->trans('Users & shipping rules', [], 'Modules.Bxmarketplace.Admin') . '</div><div class="row"><div class="col-md-6"><table class="table"><tr><th>Customer</th><th>Role</th><th>Active</th></tr>';
        foreach ($users as $u) {
            $h .= '<tr><td>' . htmlspecialchars((string) $u['email']) . '</td><td>' . htmlspecialchars((string) $u['role']) . '</td><td>' . ((int) $u['active'] ? '✔' : '✘') . '</td></tr>';
        }
        $h .= '</table><a class="btn btn-default" href="' . htmlspecialchars($this->context->link->getAdminLink('AdminBxmpVendorUsers')) . '">' . $this->trans('Manage vendor users', [], 'Modules.Bxmarketplace.Admin') . '</a></div><div class="col-md-6"><table class="table"><tr><th>Country</th><th>Zip</th><th>Excluded</th><th>Base</th><th>Reduced</th><th>Free ≥</th><th>Prio</th></tr>';
        foreach ($rules as $r) {
            $h .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%.2f</td><td>%s</td><td>%s</td><td>%d</td></tr>',
                (int) $r['id_country'] ? htmlspecialchars((string) Country::getIsoById((int) $r['id_country'])) : 'All',
                htmlspecialchars((string) $r['zipcode_pattern']),
                (int) $r['excluded'] ? 'yes' : 'no',
                (float) $r['base_cost'],
                $r['reduced_threshold'] !== null ? sprintf('%.2f ≥ %.2f', (float) $r['reduced_cost'], (float) $r['reduced_threshold']) : '-',
                $r['free_threshold'] !== null ? sprintf('%.2f', (float) $r['free_threshold']) : '-',
                (int) $r['priority']
            );
        }
        $h .= '</table><a class="btn btn-default" href="' . htmlspecialchars($this->context->link->getAdminLink('AdminBxmpShippingRules')) . '">' . $this->trans('Manage shipping rules', [], 'Modules.Bxmarketplace.Admin') . '</a></div></div></div>';

        return $h;
    }

    public function processSave()
    {
        $name = trim((string) Tools::getValue('name'));
        $code = trim((string) Tools::getValue('code'));
        if ($code === '' && $name !== '') {
            $code = Tools::str2url($name);
        }
        $code = Tools::str2url($code);
        $_POST['code'] = $code;
        $existing = BxmpVendor::getIdByCode($code);
        if ($existing && $existing !== (int) Tools::getValue('id_vendor')) {
            $this->errors[] = $this->trans('Vendor code "%code%" is already used.', ['%code%' => $code], 'Modules.Bxmarketplace.Admin');

            return false;
        }
        $rate = (float) str_replace(',', '.', (string) Tools::getValue('commission_rate'));
        if ($rate < 0 || $rate > 100) {
            $this->errors[] = $this->trans('Commission rate must be between 0 and 100.', [], 'Modules.Bxmarketplace.Admin');

            return false;
        }
        $_POST['commission_rate'] = $rate;
        $_POST['commission_fixed'] = (float) str_replace(',', '.', (string) Tools::getValue('commission_fixed'));

        return parent::processSave();
    }
}

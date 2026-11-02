<?php
/**
 * BrainX Marketplace — Vendor portal (front office).
 *
 * Role-based vendor dashboards for the multi-vendor marketplace POC: KPIs & analytics, offers / pricing / inventory
 * with competitive price positioning, sub-order fulfilment (accept → ship → delivered), commercial data, shipping
 * rules, vendor users and CSV bridges. Vendors log in as regular PrestaShop customers linked through
 * bxmp_vendor_user (core module bxmarketplace).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

class Bxmpvendorportal extends Module
{
    public const HOOKS = ['displayCustomerAccount', 'displayNav2', 'actionFrontControllerSetMedia'];

    /** Portal pages: controller => [label, permission, icon (material icon ligature)] */
    public const PAGES = [
        'dashboard' => ['Dashboard', 'dashboard.view', 'dashboard'],
        'offers' => ['Offers & stock', 'offers.view', 'inventory_2'],
        'positioning' => ['Price positioning', 'positioning.view', 'leaderboard'],
        'orders' => ['Orders', 'orders.view', 'local_shipping'],
        'profile' => ['Company & shipping', 'profile.view', 'store'],
        'users' => ['Users', 'users.manage', 'group'],
        'csv' => ['CSV import / export', 'csv.export_orders', 'upload_file'],
    ];

    public const CONFIG_DEFAULTS = [
        'BXMPV_ORDER_NOTES' => 1,       // add a private customer-service note on the parent order on status change
        'BXMPV_PRICE_STEP' => '0.01',   // undercut step used by the suggested price
        'BXMPV_PAGE_SIZE' => 20,
    ];

    public function __construct()
    {
        $this->name = 'bxmpvendorportal';
        $this->tab = 'market_place';
        $this->version = '1.0.0';
        $this->author = 'BrainX';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['bxmarketplace'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->controllers = array_keys(self::PAGES);

        parent::__construct();

        $this->displayName = $this->trans('BrainX Marketplace — Vendor portal', [], 'Modules.Bxmpvendorportal.Admin');
        $this->description = $this->trans('Front-office vendor portal: dashboards & analytics, offers, price positioning, orders & shipments, commercial data, users.', [], 'Modules.Bxmpvendorportal.Admin');
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook(self::HOOKS)) {
            return false;
        }
        foreach (self::CONFIG_DEFAULTS as $k => $v) {
            if (Configuration::get($k) === false) {
                Configuration::updateValue($k, $v);
            }
        }

        return true;
    }

    public function uninstall()
    {
        foreach (array_keys(self::CONFIG_DEFAULTS) as $k) {
            Configuration::deleteByName($k);
        }

        return parent::uninstall();
    }

    /** Active membership of the logged-in customer (cached per request). */
    public function membership(): ?array
    {
        static $cache = [];
        $customer = $this->context->customer;
        if (!$customer || !$customer->isLogged()) {
            return null;
        }
        $id = (int) $customer->id;
        if (!array_key_exists($id, $cache)) {
            $cache[$id] = BxmpVendorUser::getMembershipForCustomer($id);
        }

        return $cache[$id];
    }

    public function hookDisplayCustomerAccount()
    {
        $m = $this->membership();
        if ($m === null) {
            return '';
        }
        $this->context->smarty->assign([
            'bxmpv_link' => $this->context->link->getModuleLink($this->name, 'dashboard'),
            'bxmpv_vendor' => $m['vendor_name'],
        ]);

        return $this->fetch('module:bxmpvendorportal/views/templates/hook/customer_account.tpl');
    }

    public function hookDisplayNav2()
    {
        $m = $this->membership();
        if ($m === null) {
            return '';
        }
        $this->context->smarty->assign(['bxmpv_link' => $this->context->link->getModuleLink($this->name, 'dashboard')]);

        return $this->fetch('module:bxmpvendorportal/views/templates/hook/nav.tpl');
    }

    public function hookActionFrontControllerSetMedia()
    {
        // tiny stylesheet for the header / my-account links (the portal pages load their own assets)
        if ($this->membership() !== null) {
            $this->context->controller->registerStylesheet('bxmpv-links', 'modules/' . $this->name . '/views/css/links.css', ['media' => 'all', 'priority' => 200]);
        }
    }

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitBxmpvendorportal')) {
            Configuration::updateValue('BXMPV_ORDER_NOTES', (int) Tools::getValue('BXMPV_ORDER_NOTES'));
            $step = (float) str_replace(',', '.', (string) Tools::getValue('BXMPV_PRICE_STEP'));
            Configuration::updateValue('BXMPV_PRICE_STEP', (string) ($step > 0 && $step < 100 ? $step : 0.01));
            $size = (int) Tools::getValue('BXMPV_PAGE_SIZE');
            Configuration::updateValue('BXMPV_PAGE_SIZE', $size >= 5 && $size <= 200 ? $size : 20);
            $out .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Admin.Notifications.Success'));
        }
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitBxmpvendorportal';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            'BXMPV_ORDER_NOTES' => (int) Configuration::get('BXMPV_ORDER_NOTES'),
            'BXMPV_PRICE_STEP' => Configuration::get('BXMPV_PRICE_STEP'),
            'BXMPV_PAGE_SIZE' => (int) Configuration::get('BXMPV_PAGE_SIZE'),
        ];
        $form = ['form' => [
            'legend' => ['title' => 'Vendor portal', 'icon' => 'icon-cogs'],
            'description' => 'Portal URL: ' . $this->context->link->getModuleLink($this->name, 'dashboard'),
            'input' => [
                ['type' => 'switch', 'label' => 'Private note on parent order', 'name' => 'BXMPV_ORDER_NOTES', 'is_bool' => true,
                    'desc' => 'Add a private customer-service message on the parent order when a vendor changes a sub-order status.',
                    'values' => [['id' => 'on', 'value' => 1, 'label' => 'Yes'], ['id' => 'off', 'value' => 0, 'label' => 'No']]],
                ['type' => 'text', 'label' => 'Suggested-price undercut step (€)', 'name' => 'BXMPV_PRICE_STEP', 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => 'Rows per page', 'name' => 'BXMPV_PAGE_SIZE', 'class' => 'fixed-width-sm'],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ]];

        return $out . $helper->generateForm([$form]);
    }
}

<?php
/**
 * BrainX Marketplace — core module of the multi-vendor marketplace POC.
 *
 * Owns the bxmp_* schema, the ObjectModels (classes/), the pure-PHP domain engines (src/Domain: CartOptimizer,
 * PriceComparator, MatchingEngine, CommissionCalculator, ShippingRuleResolver), the internal JSON API
 * (controllers/front/api.php) and the "Marketplace" back-office menu (vendors, vendor users, shipping rules, offers).
 *
 * Other bxmp* modules declare `$this->dependencies = ['bxmarketplace']` and load the autoloader with
 *   require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\ApiTokenRepository;

class Bxmarketplace extends Module
{
    /** Native hooks the core module listens to. */
    public const HOOKS = ['actionProductDelete'];

    /** Custom hooks created by the core (dispatched by core or by the satellite modules). */
    public const CUSTOM_HOOKS = [
        'actionBxmpCartOptimized' => 'BrainX Marketplace: after the cart optimizer computed an allocation (params: lines, destination, result)',
        'actionBxmpOfferUpdated' => 'BrainX Marketplace: after a vendor offer was created/updated (params: id_offer, data, source)',
        'actionBxmpVendorOrderCreated' => 'BrainX Marketplace: after a vendor sub-order was materialised (params: id_vendor_order, id_order, id_vendor)',
        'actionBxmpVendorOrderStatusUpdate' => 'BrainX Marketplace: after a vendor sub-order changed status (params: id_vendor_order, old_status, new_status)',
    ];

    /** class_name => [label, parent class_name] — the parent tab first. */
    public const TABS = [
        'AdminBxMarketplace' => ['Marketplace', 'SELL'],
        'AdminBxmpVendors' => ['Vendors', 'AdminBxMarketplace'],
        'AdminBxmpVendorUsers' => ['Vendor users', 'AdminBxMarketplace'],
        'AdminBxmpShippingRules' => ['Shipping rules', 'AdminBxMarketplace'],
        'AdminBxmpOffers' => ['Offers', 'AdminBxMarketplace'],
    ];

    public const CONFIG_DEFAULTS = [
        'BXMP_OPT_EXACT_MAX_LINES' => 12,
        'BXMP_OPT_NODE_BUDGET' => 400000,
        'BXMP_COMMISSION_ON_SHIPPING' => 0,
    ];

    public function __construct()
    {
        $this->name = 'bxmarketplace';
        $this->tab = 'market_place';
        $this->version = '1.0.0';
        $this->author = 'BrainX';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('BrainX Marketplace (core)', [], 'Modules.Bxmarketplace.Admin');
        $this->description = $this->trans('Multi-vendor marketplace core: vendors, offers, shipping rules, cart optimizer, price comparison, matching, commissions and internal API.', [], 'Modules.Bxmarketplace.Admin');
        $this->confirmUninstall = $this->trans('Uninstalling drops all marketplace tables (vendors, offers, vendor orders...). Continue?', [], 'Modules.Bxmarketplace.Admin');
    }

    // ------------------------------------------------------------------ install / uninstall

    public function install()
    {
        return parent::install()
            && $this->installSql()
            && $this->installCustomHooks()
            && $this->registerHook(self::HOOKS)
            && $this->installTabs()
            && $this->installConfig();
    }

    public function uninstall()
    {
        return $this->uninstallTabs()
            && $this->runSqlFile('uninstall.php')
            && $this->uninstallConfig()
            && parent::uninstall();
    }

    public function installSql(): bool
    {
        return $this->runSqlFile('install.php');
    }

    private function runSqlFile(string $file): bool
    {
        $statements = require __DIR__ . '/sql/' . $file;
        foreach ($statements as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                $this->_errors[] = 'SQL error: ' . Db::getInstance()->getMsgError();

                return false;
            }
        }

        return true;
    }

    public function installCustomHooks(): bool
    {
        foreach (self::CUSTOM_HOOKS as $name => $description) {
            if (Hook::getIdByName($name, true, true)) {
                continue;
            }
            $hook = new Hook();
            $hook->name = $name;
            $hook->title = $name;
            $hook->description = $description;
            $hook->position = false;
            if (!$hook->add()) {
                return false;
            }
        }

        return true;
    }

    public function installTabs(): bool
    {
        $languages = Language::getLanguages(false);
        foreach (self::TABS as $class => [$label, $parent]) {
            $id = (int) Tab::getIdFromClassName($class);
            $tab = new Tab($id ?: null);
            $tab->class_name = $class;
            $tab->module = $this->name;
            $tab->active = true;
            $tab->id_parent = (int) Tab::getIdFromClassName($parent);
            if ($class === 'AdminBxMarketplace') {
                $tab->icon = 'store';
            }
            foreach ($languages as $lang) {
                $tab->name[(int) $lang['id_lang']] = $label;
            }
            if (!$tab->save()) {
                return false;
            }
        }

        return true;
    }

    public function uninstallTabs(): bool
    {
        foreach (array_reverse(array_keys(self::TABS)) as $class) {
            $id = (int) Tab::getIdFromClassName($class);
            if ($id) {
                (new Tab($id))->delete();
            }
        }

        return true;
    }

    private function installConfig(): bool
    {
        foreach (self::CONFIG_DEFAULTS as $k => $v) {
            if (Configuration::get($k) === false) {
                Configuration::updateValue($k, $v);
            }
        }

        return true;
    }

    private function uninstallConfig(): bool
    {
        foreach (array_keys(self::CONFIG_DEFAULTS) as $k) {
            Configuration::deleteByName($k);
        }

        return true;
    }

    // ------------------------------------------------------------------ hooks

    /** A deleted master product takes its vendor offers with it. */
    public function hookActionProductDelete(array $params): void
    {
        $idProduct = (int) ($params['id_product'] ?? (isset($params['product']) ? $params['product']->id : 0));
        if ($idProduct > 0) {
            Db::getInstance()->delete('bxmp_offer', 'id_product = ' . $idProduct);
            Logger::channel('catalog')->info('Offers removed with deleted product', ['id_product' => $idProduct]);
        }
    }

    // ------------------------------------------------------------------ configuration page (API tokens + optimizer)

    public function getContent()
    {
        $out = '';
        $tokens = new ApiTokenRepository();

        if (Tools::isSubmit('submitBxmpNewToken')) {
            $label = trim((string) Tools::getValue('BXMP_TOKEN_LABEL'));
            $idVendor = (int) Tools::getValue('BXMP_TOKEN_VENDOR');
            if ($label === '' || !Validate::isGenericName($label)) {
                $out .= $this->displayError($this->trans('Please enter a valid label.', [], 'Modules.Bxmarketplace.Admin'));
            } else {
                $token = $tokens->issue($label, $idVendor ?: null);
                Logger::channel('api')->info('API token issued', ['label' => $label, 'id_vendor' => $idVendor ?: null]);
                $out .= $this->displayConfirmation($this->trans('New API token (copy it now): %token%', ['%token%' => $token], 'Modules.Bxmarketplace.Admin'));
            }
        } elseif (Tools::isSubmit('revokeBxmpToken')) {
            $tokens->revoke((int) Tools::getValue('id_api_token'));
            $out .= $this->displayConfirmation($this->trans('Token revoked.', [], 'Modules.Bxmarketplace.Admin'));
        } elseif (Tools::isSubmit('submitBxmpSettings')) {
            Configuration::updateValue('BXMP_OPT_EXACT_MAX_LINES', max(0, min(20, (int) Tools::getValue('BXMP_OPT_EXACT_MAX_LINES'))));
            Configuration::updateValue('BXMP_OPT_NODE_BUDGET', max(1000, (int) Tools::getValue('BXMP_OPT_NODE_BUDGET')));
            Configuration::updateValue('BXMP_COMMISSION_ON_SHIPPING', (int) (bool) Tools::getValue('BXMP_COMMISSION_ON_SHIPPING'));
            $out .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Modules.Bxmarketplace.Admin'));
        }

        $list = array_map(static function (array $t): array {
            $t['masked'] = substr((string) $t['token'], 0, 8) . '…' . substr((string) $t['token'], -4);
            unset($t['token']);

            return $t;
        }, $tokens->all());
        $this->context->smarty->assign([
            'bxmp_tokens' => $list,
            'bxmp_token_count' => count($list),
            'bxmp_api_url' => $this->context->link->getModuleLink($this->name, 'api', [], null, null, null, true),
            'bxmp_revoke_url' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name, 'revokeBxmpToken' => 1]),
            'bxmp_links' => [
                'Vendors' => $this->context->link->getAdminLink('AdminBxmpVendors'),
                'Vendor users' => $this->context->link->getAdminLink('AdminBxmpVendorUsers'),
                'Shipping rules' => $this->context->link->getAdminLink('AdminBxmpShippingRules'),
                'Offers' => $this->context->link->getAdminLink('AdminBxmpOffers'),
            ],
        ]);
        $out .= $this->display(__FILE__, 'views/templates/admin/configure.tpl');

        return $out . $this->renderTokenForm() . $this->renderSettingsForm();
    }

    private function helper(string $submit): HelperForm
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = $submit;

        return $helper;
    }

    private function renderTokenForm(): string
    {
        $vendors = [['id' => 0, 'name' => $this->trans('— Admin scope (all vendors) —', [], 'Modules.Bxmarketplace.Admin')]];
        foreach (BxmpVendor::getNames() as $id => $name) {
            $vendors[] = ['id' => $id, 'name' => $name];
        }
        $helper = $this->helper('submitBxmpNewToken');
        $helper->fields_value = ['BXMP_TOKEN_LABEL' => '', 'BXMP_TOKEN_VENDOR' => 0];

        return $helper->generateForm([[
            'form' => [
                'legend' => ['title' => $this->trans('Issue a new API token', [], 'Modules.Bxmarketplace.Admin'), 'icon' => 'icon-key'],
                'input' => [
                    ['type' => 'text', 'label' => $this->trans('Label', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'BXMP_TOKEN_LABEL', 'required' => true],
                    ['type' => 'select', 'label' => $this->trans('Scope', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'BXMP_TOKEN_VENDOR',
                        'desc' => $this->trans('A vendor-scoped token can only read/write that vendor\'s offers and orders.', [], 'Modules.Bxmarketplace.Admin'),
                        'options' => ['query' => $vendors, 'id' => 'id', 'name' => 'name']],
                ],
                'submit' => ['title' => $this->trans('Generate token', [], 'Modules.Bxmarketplace.Admin')],
            ],
        ]]);
    }

    private function renderSettingsForm(): string
    {
        $helper = $this->helper('submitBxmpSettings');
        $helper->fields_value = [];
        foreach (array_keys(self::CONFIG_DEFAULTS) as $k) {
            $helper->fields_value[$k] = Configuration::get($k);
        }

        return $helper->generateForm([[
            'form' => [
                'legend' => ['title' => $this->trans('Optimizer & commission settings', [], 'Modules.Bxmarketplace.Admin'), 'icon' => 'icon-cogs'],
                'input' => [
                    ['type' => 'text', 'label' => $this->trans('Exact search max distinct lines', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'BXMP_OPT_EXACT_MAX_LINES', 'class' => 'fixed-width-sm',
                        'desc' => $this->trans('Baskets up to this many distinct products are solved with proven-optimal branch-and-bound; larger ones use the heuristic.', [], 'Modules.Bxmarketplace.Admin')],
                    ['type' => 'text', 'label' => $this->trans('Branch-and-bound node budget', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'BXMP_OPT_NODE_BUDGET', 'class' => 'fixed-width-md'],
                    ['type' => 'switch', 'label' => $this->trans('Apply commission rate on shipping', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'BXMP_COMMISSION_ON_SHIPPING', 'is_bool' => true,
                        'values' => [['id' => 'on', 'value' => 1, 'label' => 'Yes'], ['id' => 'off', 'value' => 0, 'label' => 'No']]],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ]]);
    }
}

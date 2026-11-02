<?php
/**
 * BrainX Marketplace — central administration & analytics (scope modules 7 and 9).
 *
 * Back-office tabs under Sell → Marketplace: dashboard (GMV, commission revenue, orders, customers, conversion, vendor
 * league...), commissions (per vendor order, automated recalculation), commission adjustments, settlements
 * (draft → approved → paid), reconciliation and commercial conditions. Every report exports to CSV.
 *
 * No own tables: works on the core bxmp_* schema (bxmp_vendor_order, bxmp_commission_adjustment, bxmp_settlement...).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

class Bxmpadmin extends Module
{
    /** class_name => label (all under the core parent tab AdminBxMarketplace), in menu order. */
    public const TABS = [
        'AdminBxmpDashboard' => 'Marketplace dashboard',
        'AdminBxmpCommissions' => 'Commissions',
        'AdminBxmpAdjustments' => 'Commission adjustments',
        'AdminBxmpSettlements' => 'Settlements',
        'AdminBxmpReconciliation' => 'Reconciliation',
        'AdminBxmpConditions' => 'Commercial conditions',
    ];

    public function __construct()
    {
        $this->name = 'bxmpadmin';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'BrainX';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['bxmarketplace'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('BrainX Marketplace — central administration', [], 'Modules.Bxmpadmin.Admin');
        $this->description = $this->trans('Marketplace dashboard & analytics, vendor commissions, adjustments, settlements, reconciliation and CSV exports.', [], 'Modules.Bxmpadmin.Admin');
    }

    public function install()
    {
        return parent::install() && $this->installTabs();
    }

    public function uninstall()
    {
        return $this->uninstallTabs() && parent::uninstall();
    }

    public function installTabs(): bool
    {
        $idParent = (int) Tab::getIdFromClassName('AdminBxMarketplace');
        if (!$idParent) {
            $this->_errors[] = 'Parent tab AdminBxMarketplace not found: install bxmarketplace first.';

            return false;
        }
        $languages = Language::getLanguages(false);
        foreach (self::TABS as $class => $label) {
            $id = (int) Tab::getIdFromClassName($class);
            $tab = new Tab($id ?: null);
            $tab->class_name = $class;
            $tab->module = $this->name;
            $tab->active = true;
            $tab->id_parent = $idParent;
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
        foreach (array_keys(self::TABS) as $class) {
            $id = (int) Tab::getIdFromClassName($class);
            if ($id) {
                (new Tab($id))->delete();
            }
        }

        return true;
    }

    /** "Configure" opens the dashboard. */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminBxmpDashboard'));
    }
}

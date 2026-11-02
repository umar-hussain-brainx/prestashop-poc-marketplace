<?php
/**
 * BrainX Marketplace — CSV import / export & synchronisation (scope modules 2 and 3 — exception queue).
 *
 * - Unified vendor CSV schema (docs/CSV_SCHEMA.md), strict per-row validation, delimiter / BOM / decimal detection
 * - Asynchronous import jobs (bxmp_import_job) processed in chunks by a worker: CLI tools/worker.php or the
 *   token-protected cron front controller (index.php?fc=module&module=bxmpcsv&controller=cron&token=...)
 * - Per row: core MatchingEngine → offer upsert / master product creation / matching exception
 * - Scheduled feeds (bxmp_feed), vendor order export (CSV) and order status import
 * - Back office: Marketplace > Imports, Feeds, Matching exceptions, Order export
 *
 * Public PHP API for other modules: see README.md (BrainX\Csv\ImportService, OrderExportService, OrderStatusService...).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

use BrainX\Csv\Config;

class Bxmpcsv extends Module
{
    /** class_name => label (parent AdminBxMarketplace, created by the core module). */
    public const TABS = [
        'AdminBxmpImports' => 'Imports',
        'AdminBxmpFeeds' => 'Feeds',
        'AdminBxmpMatchExceptions' => 'Matching exceptions',
        'AdminBxmpOrderExport' => 'Order export',
    ];

    public function __construct()
    {
        $this->name = 'bxmpcsv';
        $this->tab = 'market_place';
        $this->version = '1.0.0';
        $this->author = 'BrainX';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['bxmarketplace'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('BrainX Marketplace — CSV import/export & sync', [], 'Modules.Bxmpcsv.Admin');
        $this->description = $this->trans('Vendor catalog/offer CSV imports (async jobs, cron), scheduled feeds, matching exception queue, vendor order export and status import.', [], 'Modules.Bxmpcsv.Admin');
    }

    public function install()
    {
        return parent::install()
            && Config::install()
            && $this->installTabs()
            && is_dir(Config::jobsDir());
    }

    public function uninstall()
    {
        // Jobs, feeds and exceptions live in core tables (bxmarketplace) and are kept.
        return $this->uninstallTabs() && Config::uninstall() && parent::uninstall();
    }

    public function installTabs(): bool
    {
        $idParent = (int) Tab::getIdFromClassName('AdminBxMarketplace');
        if (!$idParent) {
            $this->_errors[] = 'Parent tab AdminBxMarketplace missing: install bxmarketplace first.';

            return false;
        }
        foreach (self::TABS as $class => $label) {
            $id = (int) Tab::getIdFromClassName($class);
            $tab = new Tab($id ?: null);
            $tab->class_name = $class;
            $tab->module = $this->name;
            $tab->active = true;
            $tab->id_parent = $idParent;
            foreach (Language::getLanguages(false) as $lang) {
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

    // ------------------------------------------------------------------ configuration page

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitBxmpcsvSettings')) {
            foreach (['BXMPCSV_AUTO_CREATE', 'BXMPCSV_FULL_SYNC', 'BXMPCSV_IMPORT_IMAGES', 'BXMPCSV_CREATE_CATEGORIES', 'BXMPCSV_PRODUCT_ACTIVE', 'BXMPCSV_EAN_DECIDES_NEW', 'BXMPCSV_SYNC_STOCK'] as $k) {
                Configuration::updateValue($k, (int) (bool) Tools::getValue($k));
            }
            Configuration::updateValue('BXMPCSV_CHUNK_SIZE', max(10, min(5000, (int) Tools::getValue('BXMPCSV_CHUNK_SIZE'))));
            Configuration::updateValue('BXMPCSV_MAX_RUNTIME', max(5, min(3600, (int) Tools::getValue('BXMPCSV_MAX_RUNTIME'))));
            Configuration::updateValue('BXMPCSV_ID_CATEGORY', max(0, (int) Tools::getValue('BXMPCSV_ID_CATEGORY')));
            Configuration::updateValue('BXMPCSV_TAX_RULES_GROUP', max(0, (int) Tools::getValue('BXMPCSV_TAX_RULES_GROUP')));
            $t = (float) str_replace(',', '.', (string) Tools::getValue('BXMPCSV_TITLE_THRESHOLD'));
            Configuration::updateValue('BXMPCSV_TITLE_THRESHOLD', (string) max(0.3, min(1.0, $t ?: 0.6)));
            Configuration::updateValue('BXMPCSV_ALLOWED_PATHS', trim(strip_tags((string) Tools::getValue('BXMPCSV_ALLOWED_PATHS'))));
            $out .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Admin.Notifications.Success'));
        } elseif (Tools::isSubmit('regenerateBxmpcsvToken')) {
            Configuration::updateValue('BXMPCSV_CRON_TOKEN', bin2hex(random_bytes(16)));
            $out .= $this->displayConfirmation($this->trans('New cron token generated: update your crontab.', [], 'Modules.Bxmpcsv.Admin'));
        }

        $this->context->smarty->assign([
            'bxmpcsv_cron_url' => Config::cronUrl(),
            'bxmpcsv_cli' => 'php ' . _PS_MODULE_DIR_ . 'bxmpcsv/tools/worker.php --max-time=' . Config::int('BXMPCSV_MAX_RUNTIME'),
            'bxmpcsv_regen_url' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name, 'regenerateBxmpcsvToken' => 1]),
            'bxmpcsv_links' => array_combine(array_values(self::TABS), array_map(fn ($c) => $this->context->link->getAdminLink($c), array_keys(self::TABS))),
        ]);
        $out .= $this->display(__FILE__, 'views/templates/admin/configure.tpl');

        return $out . $this->renderSettingsForm();
    }

    private function renderSettingsForm(): string
    {
        $switch = fn (string $name, string $label, string $desc = '') => [
            'type' => 'switch', 'label' => $label, 'name' => $name, 'is_bool' => true, 'desc' => $desc,
            'values' => [['id' => $name . '_on', 'value' => 1, 'label' => 'Yes'], ['id' => $name . '_off', 'value' => 0, 'label' => 'No']],
        ];
        $form = ['form' => [
            'legend' => ['title' => $this->trans('Import settings', [], 'Modules.Bxmpcsv.Admin'), 'icon' => 'icon-cogs'],
            'input' => [
                $switch('BXMPCSV_AUTO_CREATE', 'Auto-create master products', 'Rows without any match create a PS product (else they go to the matching exception queue).'),
                $switch('BXMPCSV_FULL_SYNC', 'Full sync mode (feeds)', 'Offers of the vendor missing from a feed / full import are set to quantity 0.'),
                $switch('BXMPCSV_IMPORT_IMAGES', 'Import images', 'Download the image URLs of newly created master products (best-effort).'),
                $switch('BXMPCSV_CREATE_CATEGORIES', 'Create missing categories', 'Category paths "A > B" are created under Home when missing.'),
                $switch('BXMPCSV_PRODUCT_ACTIVE', 'Created products are active'),
                $switch('BXMPCSV_EAN_DECIDES_NEW', 'EAN decides "new"', 'A row with an unknown EAN is not blocked by title-similar products that carry another EAN.'),
                $switch('BXMPCSV_SYNC_STOCK', 'Sync PS stock', 'PrestaShop stock = sum of the active vendor offers.'),
                ['type' => 'text', 'label' => 'Chunk size (rows)', 'name' => 'BXMPCSV_CHUNK_SIZE', 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => 'Max runtime per cron run (s)', 'name' => 'BXMPCSV_MAX_RUNTIME', 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => 'Default category id (0 = Home)', 'name' => 'BXMPCSV_ID_CATEGORY', 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => 'Tax rules group id of created products', 'name' => 'BXMPCSV_TAX_RULES_GROUP', 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => 'Title similarity threshold', 'name' => 'BXMPCSV_TITLE_THRESHOLD', 'class' => 'fixed-width-sm', 'desc' => 'Matching engine: title candidates above this score are sent to review.'],
                ['type' => 'text', 'label' => 'Extra allowed server directories', 'name' => 'BXMPCSV_ALLOWED_PATHS', 'desc' => 'Comma separated; var/bxmp_feeds is always allowed.'],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ]];
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitBxmpcsvSettings';
        foreach (array_keys(Config::DEFAULTS) as $k) {
            $helper->fields_value[$k] = Config::get($k);
        }

        return $helper->generateForm([$form]);
    }
}

<?php
/**
 * BrainX Marketplace — custom categories & scheduled thematic collections (scope module 10).
 *
 * - BO "Collections": CRUD on the core bxmp_collection (+ products / whole categories), preview of resolved products.
 * - BO "Custom categories": marketplace category tree (native Category ObjectModel) + vendor label → category mapping.
 * - Scheduling: ScheduleResolver (active AND now within [date_start, date_end]) evaluated at render time; cron
 *   (front controller "cron" or tools/cron.php) materialises / logs transitions and clears caches.
 * - Front office: /collection/{slug} landing page with the theme product miniatures, /collections index,
 *   displayHome block and displayNavFullWidth menu strip listing live collections.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

use BrainX\Collections\Repository\CollectionRepository;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

class Bxmpcollections extends Module
{
    public const HOOKS = [
        'moduleRoutes',
        'displayHome',
        'displayNavFullWidth',
        'actionFrontControllerSetMedia',
        'actionObjectBxmpCollectionAddAfter',
        'actionObjectBxmpCollectionUpdateAfter',
        'actionObjectBxmpCollectionDeleteAfter',
    ];

    public const CUSTOM_HOOKS = [
        'actionBxmpCollectionStatusChange' => 'BrainX Marketplace: a collection was activated/deactivated by its schedule (params: id_collection, slug, name, from, to, event)',
    ];

    public const TABS = [
        'AdminBxmpCollections' => 'Collections',
        'AdminBxmpCategories' => 'Custom categories',
    ];

    public const HOME_PRODUCTS = 4;

    public function __construct()
    {
        $this->name = 'bxmpcollections';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'BrainX';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['bxmarketplace'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('BrainX Marketplace — categories & thematic collections', [], 'Modules.Bxmpcollections.Admin');
        $this->description = $this->trans('Custom marketplace category management and cross-category thematic collections with scheduled activation.', [], 'Modules.Bxmpcollections.Admin');
    }

    // ------------------------------------------------------------------ install

    public function install()
    {
        if (!Configuration::get('BXMPCOL_CRON_TOKEN')) {
            Configuration::updateValue('BXMPCOL_CRON_TOKEN', Tools::passwdGen(32));
        }

        return parent::install()
            && $this->runSql('install.php')
            && $this->installCustomHooks()
            && $this->registerHook(self::HOOKS)
            && $this->installTabs()
            && $this->seedIfEmpty();
    }

    public function uninstall()
    {
        foreach (array_keys(self::TABS) as $class) {
            if ($id = (int) Tab::getIdFromClassName($class)) {
                (new Tab($id))->delete();
            }
        }
        Configuration::deleteByName('BXMPCOL_CRON_TOKEN');

        // collections themselves stay in the core tables (owned by bxmarketplace)
        return $this->runSql('uninstall.php') && parent::uninstall();
    }

    private function runSql(string $file): bool
    {
        foreach (require __DIR__ . '/sql/' . $file as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                $this->_errors[] = 'SQL error: ' . Db::getInstance()->getMsgError();

                return false;
            }
        }

        return true;
    }

    private function installCustomHooks(): bool
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

    private function installTabs(): bool
    {
        $idParent = (int) Tab::getIdFromClassName('AdminBxMarketplace');
        if (!$idParent) {
            $this->_errors[] = 'Parent tab AdminBxMarketplace not found: install bxmarketplace first.';

            return false;
        }
        foreach (self::TABS as $class => $label) {
            $tab = new Tab((int) Tab::getIdFromClassName($class) ?: null);
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

    private function seedIfEmpty(): bool
    {
        if (!(int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_collection`')) {
            require_once __DIR__ . '/tools/DemoCollections.php';
            BxmpDemoCollections::seed();
        }

        return true;
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminBxmpCollections'));
    }

    // ------------------------------------------------------------------ helpers

    public function cronUrl(): string
    {
        return $this->context->link->getModuleLink($this->name, 'cron', ['token' => Configuration::get('BXMPCOL_CRON_TOKEN')]);
    }

    public function collectionUrl(string $slug, array $params = []): string
    {
        return $this->context->link->getModuleLink($this->name, 'collection', ['slug' => $slug] + $params);
    }

    /** Preview token allowing admins to open a non-live collection page. */
    public static function previewToken(int $idCollection): string
    {
        return substr(hash('sha256', _COOKIE_KEY_ . '|bxmpcol|' . $idCollection), 0, 20);
    }

    public function clearCollectionsCache(): void
    {
        $this->_clearCache('*');
    }

    /**
     * Present products with the theme's listing presenter (same as ps_featuredproducts), ready for
     * catalog/_partials/miniatures/product.tpl.
     *
     * @param int[] $ids
     */
    public function presentProducts(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $assembler = new ProductAssembler($this->context);
        $presenterFactory = new ProductPresenterFactory($this->context);
        $settings = $presenterFactory->getPresentationSettings();
        $presenter = new ProductListingPresenter(new ImageRetriever($this->context->link), $this->context->link, new PriceFormatter(), new ProductColorsRetriever(), $this->context->getTranslator());
        $raw = array_map(static fn ($id) => ['id_product' => (int) $id], array_values($ids));
        $raw = method_exists($assembler, 'assembleProducts') ? $assembler->assembleProducts($raw) : array_map([$assembler, 'assembleProduct'], $raw);
        $out = [];
        foreach ($raw as $r) {
            $out[] = $presenter->present($settings, $r, $this->context->language);
        }

        return $out;
    }

    // ------------------------------------------------------------------ hooks

    public function hookModuleRoutes(): array
    {
        return [
            'module-bxmpcollections-collection' => [
                'controller' => 'collection',
                'rule' => 'collection/{slug}',
                'keywords' => ['slug' => ['regexp' => '[_a-zA-Z0-9-]+', 'param' => 'slug']],
                'params' => ['fc' => 'module', 'module' => 'bxmpcollections'],
            ],
            'module-bxmpcollections-collections' => [
                'controller' => 'collections',
                'rule' => 'collections',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => 'bxmpcollections'],
            ],
        ];
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        $this->context->controller->registerStylesheet('bxmpcollections-front', 'modules/' . $this->name . '/views/css/front.css', ['media' => 'all', 'priority' => 200]);
    }

    public function hookDisplayHome(): string
    {
        $live = (new CollectionRepository())->live();
        if (!$live) {
            return '';
        }
        $repo = new CollectionRepository();
        foreach ($live as &$c) {
            $c['url'] = $this->collectionUrl($c['slug']);
            $ids = $repo->resolveProductIds((int) $c['id_collection']);
            $c['count'] = count($ids);
            $c['products'] = $this->presentProducts(array_slice($ids, 0, self::HOME_PRODUCTS));
            $c['excerpt'] = Tools::truncateString(strip_tags((string) $c['description']), 140);
        }
        unset($c);
        $this->context->smarty->assign(['bxmp_collections' => $live, 'bxmp_collections_url' => $this->context->link->getModuleLink($this->name, 'collections')]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/home.tpl');
    }

    public function hookDisplayNavFullWidth(): string
    {
        $live = (new CollectionRepository())->live();
        if (!$live) {
            return '';
        }
        $current = Tools::getValue('module') === $this->name ? (string) Tools::getValue('slug') : '';
        $items = array_map(fn ($c) => ['name' => $c['name'], 'slug' => $c['slug'], 'url' => $this->collectionUrl($c['slug']), 'current' => $c['slug'] === $current], $live);
        $this->context->smarty->assign(['bxmp_nav_collections' => $items, 'bxmp_collections_url' => $this->context->link->getModuleLink($this->name, 'collections')]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/nav.tpl');
    }

    public function hookActionObjectBxmpCollectionAddAfter(): void
    {
        $this->clearCollectionsCache();
    }

    public function hookActionObjectBxmpCollectionUpdateAfter(): void
    {
        $this->clearCollectionsCache();
    }

    public function hookActionObjectBxmpCollectionDeleteAfter(array $params): void
    {
        if (isset($params['object']->id)) {
            (new CollectionRepository())->deleteLinks((int) $params['object']->id);
        }
        $this->clearCollectionsCache();
    }
}

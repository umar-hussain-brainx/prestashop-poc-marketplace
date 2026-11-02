<?php
/**
 * Custom category management: the marketplace category tree used for matching / import, managed through the native
 * Category ObjectModel (create, rename, move, activate/deactivate), plus the vendor category label → PS category
 * mapping table (bxmp_category_map) consumed by the CSV importer via CategoryMapRepository::resolve().
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpcollections/vendor/autoload.php';

use BrainX\Collections\Repository\CategoryMapRepository;
use BrainX\Marketplace\Infrastructure\Logger;

class AdminBxmpCategoriesController extends ModuleAdminController
{
    private CategoryMapRepository $maps;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->maps = new CategoryMapRepository();
        $this->meta_title = ['Custom categories'];
    }

    /** Flat tree in nested-set order: id, parent, depth, name, active, product and mapping counts. */
    private function tree(): array
    {
        $idLang = (int) $this->context->language->id;
        $rows = Db::getInstance()->executeS('SELECT c.id_category, c.id_parent, c.level_depth, c.active, c.nleft, c.nright, cl.name, cl.link_rewrite,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category_product` cp WHERE cp.id_category = c.id_category) AS products
            FROM `' . _DB_PREFIX_ . 'category` c
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . (int) $this->context->shop->id . '
            ORDER BY c.nleft') ?: [];
        $maps = $this->maps->countsByCategory();
        foreach ($rows as &$r) {
            $r['mappings'] = $maps[(int) $r['id_category']] ?? 0;
            $r['indent'] = str_repeat('—', max(0, (int) $r['level_depth'] - 1));
            $r['is_root'] = (int) $r['id_category'] === (int) Configuration::get('PS_ROOT_CATEGORY');
            $r['is_home'] = (int) $r['id_category'] === (int) Configuration::get('PS_HOME_CATEGORY');
        }

        return $rows;
    }

    private function names(array $languages, string $name): array
    {
        $out = [];
        foreach ($languages as $l) {
            $out[(int) $l['id_lang']] = $name;
        }

        return $out;
    }

    public function postProcess()
    {
        $action = (string) Tools::getValue('bxmp_cat_action');
        if ($action === '') {
            return;
        }
        if (!$this->access('edit')) {
            $this->errors[] = $this->trans('You do not have permission to edit this.', [], 'Admin.Notifications.Error');

            return;
        }
        $languages = Language::getLanguages(false);
        try {
            switch ($action) {
                case 'create':
                    $name = trim((string) Tools::getValue('name'));
                    $parent = (int) Tools::getValue('id_parent') ?: (int) Configuration::get('PS_HOME_CATEGORY');
                    if ($name === '' || !Validate::isCatalogName($name)) {
                        throw new RuntimeException('Invalid category name.');
                    }
                    if (!Validate::isLoadedObject(new Category($parent))) {
                        throw new RuntimeException('Unknown parent category.');
                    }
                    $cat = new Category();
                    $cat->name = $this->names($languages, $name);
                    $cat->link_rewrite = $this->names($languages, Tools::str2url($name));
                    $cat->id_parent = $parent;
                    $cat->active = (bool) Tools::getValue('active', 1);
                    if (!$cat->add()) {
                        throw new RuntimeException('Could not create the category.');
                    }
                    $msg = sprintf('Category "%s" created (#%d).', $name, $cat->id);
                    break;
                case 'rename':
                    $cat = $this->editable((int) Tools::getValue('id_category'));
                    $name = trim((string) Tools::getValue('name'));
                    if ($name === '' || !Validate::isCatalogName($name)) {
                        throw new RuntimeException('Invalid category name.');
                    }
                    $cat->name = $this->names($languages, $name);
                    $cat->link_rewrite = $this->names($languages, Tools::str2url($name));
                    $cat->groupBox = $cat->getGroups();
                    $cat->update();
                    $msg = sprintf('Category #%d renamed to "%s".', $cat->id, $name);
                    break;
                case 'move':
                    $cat = $this->editable((int) Tools::getValue('id_category'));
                    $parent = new Category((int) Tools::getValue('id_parent'));
                    if (!Validate::isLoadedObject($parent)) {
                        throw new RuntimeException('Unknown target category.');
                    }
                    if ((int) $parent->id === (int) $cat->id || ($parent->nleft > $cat->nleft && $parent->nright < $cat->nright)) {
                        throw new RuntimeException('A category cannot be moved under itself or one of its sub-categories.');
                    }
                    $cat->id_parent = (int) $parent->id;
                    $cat->groupBox = $cat->getGroups();
                    $cat->update();
                    $msg = sprintf('Category "%s" moved under "%s".', $cat->getName(), $parent->getName());
                    break;
                case 'toggle':
                    $cat = $this->editable((int) Tools::getValue('id_category'));
                    $cat->active = !$cat->active;
                    $cat->groupBox = $cat->getGroups();
                    $cat->update();
                    $msg = sprintf('Category "%s" %s.', $cat->getName(), $cat->active ? 'activated' : 'deactivated');
                    break;
                case 'map_save':
                    $id = $this->maps->save((string) Tools::getValue('label'), (int) Tools::getValue('map_id_category'), (int) Tools::getValue('map_id_vendor'));
                    $msg = sprintf('Mapping #%d saved.', $id);
                    break;
                case 'map_delete':
                    $this->maps->delete((int) Tools::getValue('id_category_map'));
                    $msg = 'Mapping deleted.';
                    break;
                default:
                    return;
            }
            Logger::channel('categories')->info($msg, ['action' => $action]);
            $this->context->cookie->bxmpcat_flash = $msg;
            $this->context->cookie->write();
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminBxmpCategories'));
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    private function editable(int $id): Category
    {
        $cat = new Category($id);
        if (!Validate::isLoadedObject($cat)) {
            throw new RuntimeException('Unknown category.');
        }
        if (in_array($id, [(int) Configuration::get('PS_ROOT_CATEGORY'), (int) Configuration::get('PS_HOME_CATEGORY')], true)) {
            throw new RuntimeException('The root and home categories cannot be changed here.');
        }

        return $cat;
    }

    public function initContent()
    {
        if (!empty($this->context->cookie->bxmpcat_flash)) {
            $this->confirmations[] = (string) $this->context->cookie->bxmpcat_flash;
            unset($this->context->cookie->bxmpcat_flash);
        }
        $tree = $this->tree();
        $test = trim((string) Tools::getValue('test_label'));
        $testVendor = (int) Tools::getValue('test_vendor');
        $testResult = null;
        if ($test !== '') {
            $idc = $this->maps->resolve($test, $testVendor ?: null);
            $testResult = ['label' => $test, 'key' => CategoryMapRepository::key($test), 'id_category' => $idc, 'name' => $idc ? (new Category($idc, (int) $this->context->language->id))->name : null];
        }
        $this->context->smarty->assign([
            'tree' => $tree,
            'parents' => array_values(array_filter($tree, static fn ($r) => !$r['is_root'])),
            'maps' => $this->maps->all((int) $this->context->language->id),
            'vendors' => BxmpVendor::getNames(),
            'action_url' => $this->context->link->getAdminLink('AdminBxmpCategories'),
            'token' => $this->token,
            'native_url' => $this->context->link->getAdminLink('AdminCategories'),
            'test' => $testResult,
        ]);
        $this->content .= $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'bxmpcollections/views/templates/admin/categories.tpl');
        $this->context->smarty->assign(['content' => $this->content]);
    }
}

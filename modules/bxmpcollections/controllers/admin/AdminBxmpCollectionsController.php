<?php
/**
 * Thematic collections CRUD (core table bxmp_collection) + products / whole categories assignment,
 * schedule status, preview of the resolved products and "run schedule now".
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpcollections/vendor/autoload.php';

use BrainX\Collections\Domain\ScheduleResolver;
use BrainX\Collections\Repository\CollectionRepository;
use BrainX\Collections\Service\ScheduleRunner;
use BrainX\Marketplace\Infrastructure\Logger;

class AdminBxmpCollectionsController extends ModuleAdminController
{
    private CollectionRepository $repo;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_collection';
        $this->className = 'BxmpCollection';
        $this->identifier = 'id_collection';
        $this->lang = false;
        $this->allow_export = true;
        $this->_defaultOrderBy = 'position';
        $this->_defaultOrderWay = 'ASC';
        parent::__construct();
        $this->repo = new CollectionRepository();

        $this->_select = '(SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_collection_product` cp WHERE cp.id_collection = a.id_collection) AS products_count,
            (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_collection_category` cc WHERE cc.id_collection = a.id_collection) AS categories_count,
            a.active AS schedule_status';

        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->bulk_actions = ['delete' => ['text' => 'Delete selected', 'confirm' => 'Delete the selected collections?', 'icon' => 'icon-trash']];

        $this->fields_list = [
            'id_collection' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'name' => ['title' => 'Name'],
            'slug' => ['title' => 'Slug'],
            'date_start' => ['title' => 'Starts', 'type' => 'datetime'],
            'date_end' => ['title' => 'Ends', 'type' => 'datetime'],
            'schedule_status' => ['title' => 'Schedule status', 'callback' => 'renderStatus', 'search' => false, 'orderby' => false],
            'products_count' => ['title' => 'Products', 'search' => false, 'align' => 'center'],
            'categories_count' => ['title' => 'Categories', 'search' => false, 'align' => 'center'],
            'position' => ['title' => 'Position', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'active' => ['title' => 'Enabled', 'type' => 'bool', 'active' => 'status', 'align' => 'center', 'class' => 'fixed-width-sm'],
        ];
    }

    public function renderStatus($value, array $row): string
    {
        $status = ScheduleResolver::statusOf($row);
        $class = ['live' => 'success', 'scheduled' => 'info', 'expired' => 'warning', 'disabled' => 'danger'][$status];
        $next = ScheduleResolver::nextChange((bool) (int) $row['active'], $row['date_start'], $row['date_end']);

        return '<span class="label label-' . $class . ' bxmp-status" data-status="' . $status . '">' . $status . '</span>'
            . ($next ? '<br><small class="text-muted">changes ' . htmlspecialchars($next) . '</small>' : '');
    }

    public function initPageHeaderToolbar()
    {
        if (empty($this->display)) {
            $this->page_header_toolbar_btn['new_collection'] = ['href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token, 'desc' => 'Add collection', 'icon' => 'process-icon-new'];
            $this->page_header_toolbar_btn['run_schedule'] = ['href' => self::$currentIndex . '&runSchedule=1&token=' . $this->token, 'desc' => 'Run schedule now', 'icon' => 'process-icon-refresh'];
        }
        parent::initPageHeaderToolbar();
    }

    public function renderList()
    {
        $html = parent::renderList();
        $html .= '<div class="panel"><div class="panel-heading"><i class="icon-time"></i> Scheduling</div>'
            . '<p>A collection is shown in the shop when it is <strong>enabled</strong> and <em>now</em> is within its start/end window (empty bounds = open). '
            . 'This is evaluated on every page render, so it works without cron. The cron materialises the status changes (audit log in <code>bxmp_log</code>, channel <code>collections</code>) and clears the module caches:</p>'
            . '<ul><li>HTTP: <code>' . htmlspecialchars($this->module->cronUrl()) . '</code></li>'
            . '<li>CLI: <code>php modules/bxmpcollections/tools/cron.php</code> (crontab every 5 minutes)</li></ul>'
            . '<p>Front office: <a href="' . htmlspecialchars($this->context->link->getModuleLink('bxmpcollections', 'collections')) . '" target="_blank">/collections</a>, '
            . 'one landing page per collection at <code>/collection/{slug}</code>, home page block and top menu strip.</p></div>';

        return $html;
    }

    public function renderForm()
    {
        $this->fields_form = [
            'legend' => ['title' => 'Collection', 'icon' => 'icon-star'],
            'input' => [
                ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => true, 'maxlength' => 128],
                ['type' => 'text', 'label' => 'Slug', 'name' => 'slug', 'maxlength' => 128, 'desc' => 'URL key: /collection/{slug}. Generated from the name when empty.'],
                ['type' => 'textarea', 'label' => 'Description', 'name' => 'description', 'autoload_rte' => true],
                ['type' => 'switch', 'label' => 'Enabled', 'name' => 'active', 'is_bool' => true,
                    'values' => [['id' => 'active_on', 'value' => 1, 'label' => 'Yes'], ['id' => 'active_off', 'value' => 0, 'label' => 'No']]],
                ['type' => 'datetime', 'label' => 'Visible from', 'name' => 'date_start', 'desc' => 'Empty = immediately. Format YYYY-MM-DD HH:MM:SS.'],
                ['type' => 'datetime', 'label' => 'Visible until', 'name' => 'date_end', 'desc' => 'Empty = no end.'],
                ['type' => 'text', 'label' => 'Position', 'name' => 'position', 'class' => 'fixed-width-sm', 'desc' => 'Order in the home block and menu (ascending).'],
            ],
            'submit' => ['title' => 'Save'],
            'buttons' => [['type' => 'submit', 'title' => 'Save and stay', 'icon' => 'process-icon-save', 'name' => 'submitAdd' . $this->table . 'AndStay', 'class' => 'btn btn-default pull-right']],
        ];
        $html = parent::renderForm();
        $obj = $this->loadObject(true);
        if ($obj && $obj->id) {
            $html .= $this->renderAssignments($obj);
        } else {
            $html .= '<div class="alert alert-info">Save the collection to assign products and categories.</div>';
        }

        return $html;
    }

    private function renderAssignments(BxmpCollection $c): string
    {
        $idLang = (int) $this->context->language->id;
        $id = (int) $c->id;
        $q = trim((string) Tools::getValue('product_search'));
        $selectedCats = $this->repo->categoryIds($id);
        $tree = new HelperTreeCategories('bxmp-collection-categories', 'Whole categories (all products of the category and its sub-categories)');
        $tree->setUseCheckBox(true)->setInputName('collection_categories')->setSelectedCategories($selectedCats)->setRootCategory((int) Configuration::get('PS_ROOT_CATEGORY'))->setUseSearch(false);
        $resolved = $this->repo->resolveProductIds($id);
        $names = [];
        if ($resolved) {
            foreach (Db::getInstance()->executeS('SELECT p.id_product, pl.name, p.reference FROM `' . _DB_PREFIX_ . 'product` p LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = 1 WHERE p.id_product IN (' . implode(',', array_slice($resolved, 0, 100)) . ')') ?: [] as $r) {
                $names[(int) $r['id_product']] = $r;
            }
        }
        $status = ScheduleResolver::status((bool) $c->active, $c->date_start, $c->date_end);
        $base = self::$currentIndex . '&token=' . $this->token . '&id_collection=' . $id . '&update' . $this->table;
        $this->context->smarty->assign([
            'c' => ['id' => $id, 'name' => $c->name, 'slug' => $c->slug, 'status' => $status],
            'base_url' => $base,
            'token' => $this->token,
            'products' => $this->repo->products($id, $idLang),
            'search' => $q,
            'results' => $q !== '' ? $this->repo->searchProducts($q, $idLang) : [],
            'assigned_ids' => array_map('intval', array_column($this->repo->products($id, $idLang), 'id_product')),
            'category_tree' => $tree->render(),
            'resolved' => array_map(static fn ($pid) => $names[$pid] ?? ['id_product' => $pid, 'name' => '#' . $pid, 'reference' => ''], array_slice($resolved, 0, 100)),
            'resolved_count' => count($resolved),
            'preview_url' => $this->module->collectionUrl((string) $c->slug, $status === 'live' ? [] : ['preview' => Bxmpcollections::previewToken($id)]),
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'bxmpcollections/views/templates/admin/assignments.tpl');
    }

    public function postProcess()
    {
        $id = (int) Tools::getValue('id_collection');
        $action = (string) Tools::getValue('bxmp_action');
        if (Tools::getValue('runSchedule')) {
            $r = (new ScheduleRunner())->run();
            $this->confirmations[] = sprintf('Schedule checked for %d collection(s): %d change(s)%s.', $r['checked'], count($r['changes']),
                $r['changes'] ? ' — ' . implode(', ', array_map(static fn ($c) => $c['name'] . ' ' . $c['event'] . ' (' . $c['to'] . ')', $r['changes'])) : '')
                . ($r['next_change'] ? ' Next change at ' . $r['next_change'] . '.' : '');

            return;
        }
        if ($id && $action !== '' && $this->access('edit')) {
            $base = ['id_collection' => $id, 'update' . $this->table => 1];
            switch ($action) {
                case 'add_product':
                    foreach ((array) Tools::getValue('id_products', [(int) Tools::getValue('id_product')]) as $pid) {
                        if ((int) $pid > 0 && Validate::isLoadedObject(new Product((int) $pid))) {
                            $this->repo->addProduct($id, (int) $pid);
                        }
                    }
                    break;
                case 'remove_product':
                    $this->repo->removeProduct($id, (int) Tools::getValue('id_product'));
                    break;
                case 'move_product':
                    $this->repo->moveProduct($id, (int) Tools::getValue('id_product'), (int) Tools::getValue('direction'));
                    break;
                case 'save_categories':
                    $this->repo->setCategories($id, array_map('intval', (array) Tools::getValue('collection_categories', [])));
                    break;
                default:
                    return parent::postProcess();
            }
            Logger::channel('collections')->info('Collection content changed', ['id_collection' => $id, 'action' => $action]);
            $this->module->clearCollectionsCache();
            $params = $base + ['conf' => 4];
            if (Tools::getValue('product_search')) {
                $params['product_search'] = (string) Tools::getValue('product_search');
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminBxmpCollections', true, [], $params) . '#bxmp-assignments');
        }

        return parent::postProcess();
    }

    public function processSave()
    {
        $name = trim((string) Tools::getValue('name'));
        $slug = Tools::str2url(trim((string) Tools::getValue('slug')) ?: $name);
        $id = (int) Tools::getValue('id_collection');
        if ($slug === '' || $this->repo->slugExists($slug, $id)) {
            $this->errors[] = sprintf('The slug "%s" is empty or already used.', $slug);
        }
        $start = ScheduleResolver::normalize((string) Tools::getValue('date_start'));
        $end = ScheduleResolver::normalize((string) Tools::getValue('date_end'), true);
        if ($msg = ScheduleResolver::validateWindow((string) Tools::getValue('date_start'), (string) Tools::getValue('date_end'))) {
            $this->errors[] = $msg;
        }
        if ($this->errors) {
            $this->display = 'edit';

            return false;
        }
        $_POST['slug'] = $slug;
        $_POST['date_start'] = $start;
        $_POST['date_end'] = $end;
        $_POST['position'] = max(0, (int) Tools::getValue('position'));
        $res = parent::processSave();
        if ($res) {
            Logger::channel('collections')->info('Collection saved', ['id_collection' => (int) $this->object->id, 'slug' => $slug, 'date_start' => $start, 'date_end' => $end, 'active' => (int) Tools::getValue('active')]);
            (new ScheduleRunner())->run();
        }

        return $res;
    }
}

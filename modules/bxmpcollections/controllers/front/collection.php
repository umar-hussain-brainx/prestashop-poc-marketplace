<?php
/**
 * Collection landing page — friendly URL /collection/{slug} (hook moduleRoutes).
 * Only live collections are reachable (404 otherwise), except with an admin preview token.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use BrainX\Collections\Domain\ScheduleResolver;
use BrainX\Collections\Repository\CollectionRepository;

class BxmpcollectionsCollectionModuleFrontController extends ModuleFrontController
{
    public const PER_PAGE = 24;

    /** @var Bxmpcollections */
    public $module;

    private ?array $collection = null;

    public function init()
    {
        parent::init();
        $slug = (string) Tools::getValue('slug');
        $row = $slug !== '' ? (new CollectionRepository())->findBySlug($slug) : null;
        $preview = $row && hash_equals(Bxmpcollections::previewToken((int) $row['id_collection']), (string) Tools::getValue('preview'));
        if (!$row || (!$preview && !ScheduleResolver::isLive((bool) (int) $row['active'], $row['date_start'], $row['date_end']))) {
            header('HTTP/1.1 404 Not Found');
            header('Status: 404 Not Found');
            $this->errors[] = $this->trans('This collection is not available.', [], 'Modules.Bxmpcollections.Shop');
            $this->collection = null;

            return;
        }
        $row['status'] = ScheduleResolver::statusOf($row);
        $row['preview'] = $preview && $row['status'] !== ScheduleResolver::LIVE;
        $this->collection = $row;
    }

    public function initContent()
    {
        parent::initContent();
        if (!$this->collection) {
            $this->context->smarty->assign(['bxmp_collection' => null, 'products' => [], 'bxmp_collections_url' => $this->context->link->getModuleLink('bxmpcollections', 'collections')]);
            $this->setTemplate('module:bxmpcollections/views/templates/front/collection.tpl');

            return;
        }
        $ids = (new CollectionRepository())->resolveProductIds((int) $this->collection['id_collection']);
        $total = count($ids);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) Tools::getValue('page', 1)));
        $products = $this->module->presentProducts(array_slice($ids, ($page - 1) * self::PER_PAGE, self::PER_PAGE));
        $pageLinks = [];
        for ($i = 1; $i <= $pages; ++$i) {
            $pageLinks[] = ['n' => $i, 'url' => $this->module->collectionUrl($this->collection['slug'], $i > 1 ? ['page' => $i] : []), 'current' => $i === $page];
        }
        $this->context->smarty->assign([
            'bxmp_collection' => $this->collection,
            'products' => $products,
            'bxmp_total' => $total,
            'bxmp_pages' => $pages > 1 ? $pageLinks : [],
            'bxmp_collections_url' => $this->context->link->getModuleLink('bxmpcollections', 'collections'),
        ]);
        $this->setTemplate('module:bxmpcollections/views/templates/front/collection.tpl');
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        if ($this->collection) {
            $page['meta']['title'] = $this->collection['name'];
            $page['meta']['description'] = Tools::truncateString(strip_tags((string) $this->collection['description']), 160);
            if (!empty($this->collection['preview'])) {
                $page['meta']['robots'] = 'noindex';
            }
        } else {
            $page['meta']['robots'] = 'noindex';
        }
        $page['body_classes']['bxmp-collection-page'] = true;

        return $page;
    }

    public function getBreadcrumbLinks()
    {
        $b = parent::getBreadcrumbLinks();
        $b['links'][] = ['title' => $this->trans('Collections', [], 'Modules.Bxmpcollections.Shop'), 'url' => $this->context->link->getModuleLink('bxmpcollections', 'collections')];
        if ($this->collection) {
            $b['links'][] = ['title' => $this->collection['name'], 'url' => $this->module->collectionUrl($this->collection['slug'])];
        }

        return $b;
    }

    public function getCanonicalURL()
    {
        return $this->collection ? $this->module->collectionUrl($this->collection['slug']) : '';
    }
}

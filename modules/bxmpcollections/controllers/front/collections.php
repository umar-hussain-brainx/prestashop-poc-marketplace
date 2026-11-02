<?php
/**
 * Index of the currently live collections — friendly URL /collections.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use BrainX\Collections\Repository\CollectionRepository;

class BxmpcollectionsCollectionsModuleFrontController extends ModuleFrontController
{
    /** @var Bxmpcollections */
    public $module;

    public function initContent()
    {
        parent::initContent();
        $repo = new CollectionRepository();
        $live = $repo->live();
        foreach ($live as &$c) {
            $c['url'] = $this->module->collectionUrl($c['slug']);
            $ids = $repo->resolveProductIds((int) $c['id_collection']);
            $c['count'] = count($ids);
            $c['products'] = $this->module->presentProducts(array_slice($ids, 0, Bxmpcollections::HOME_PRODUCTS));
            $c['excerpt'] = Tools::truncateString(strip_tags((string) $c['description']), 140);
        }
        unset($c);
        $this->context->smarty->assign(['bxmp_collections' => $live]);
        $this->setTemplate('module:bxmpcollections/views/templates/front/collections.tpl');
    }

    public function getBreadcrumbLinks()
    {
        $b = parent::getBreadcrumbLinks();
        $b['links'][] = ['title' => $this->trans('Collections', [], 'Modules.Bxmpcollections.Shop'), 'url' => $this->context->link->getModuleLink('bxmpcollections', 'collections')];

        return $b;
    }
}

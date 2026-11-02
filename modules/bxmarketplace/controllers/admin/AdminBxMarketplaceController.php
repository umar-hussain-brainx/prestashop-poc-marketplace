<?php
/**
 * Parent "Marketplace" tab: redirects to the vendors list.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

class AdminBxMarketplaceController extends ModuleAdminController
{
    public function init()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminBxmpVendors'));
    }
}

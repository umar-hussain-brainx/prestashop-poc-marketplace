<?php
/**
 * Central view / edit of vendor offers (bxmp_offer).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

class AdminBxmpOffersController extends ModuleAdminController
{
    private const NULLABLE = ['discount_price', 'discount_from', 'discount_to', 'vendor_sku', 'ean13'];

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_offer';
        $this->className = 'BxmpOffer';
        $this->identifier = 'id_offer';
        $this->lang = false;
        $this->allow_export = true;
        $this->_defaultOrderBy = 'id_product';

        parent::__construct();

        $this->_select = 'v.name AS vendor_name, pl.name AS product_name';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = a.id_vendor
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = a.id_product AND pl.id_lang = ' . (int) $this->context->language->id . ' AND pl.id_shop = ' . (int) $this->context->shop->id;

        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->bulk_actions = ['delete' => ['text' => $this->trans('Delete selected', [], 'Admin.Actions'), 'icon' => 'icon-trash', 'confirm' => $this->trans('Delete selected offers?', [], 'Modules.Bxmarketplace.Admin')]];

        $this->fields_list = [
            'id_offer' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'vendor_name' => ['title' => $this->trans('Vendor', [], 'Modules.Bxmarketplace.Admin'), 'filter_key' => 'v!name'],
            'id_product' => ['title' => $this->trans('Product ID', [], 'Modules.Bxmarketplace.Admin'), 'filter_key' => 'a!id_product', 'class' => 'fixed-width-xs'],
            'product_name' => ['title' => $this->trans('Product', [], 'Admin.Global'), 'filter_key' => 'pl!name'],
            'id_product_attribute' => ['title' => $this->trans('Combination', [], 'Modules.Bxmarketplace.Admin'), 'class' => 'fixed-width-xs'],
            'vendor_sku' => ['title' => $this->trans('Vendor SKU', [], 'Modules.Bxmarketplace.Admin')],
            'ean13' => ['title' => 'EAN13'],
            'price' => ['title' => $this->trans('Price (tax excl.)', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'price'],
            'discount_price' => ['title' => $this->trans('Discount price', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'price'],
            'quantity' => ['title' => $this->trans('Stock', [], 'Modules.Bxmarketplace.Admin'), 'align' => 'center'],
            'active' => ['title' => $this->trans('Active', [], 'Admin.Global'), 'type' => 'bool', 'active' => 'status', 'align' => 'center'],
        ];
    }

    public function renderForm()
    {
        $vendors = [];
        foreach (BxmpVendor::getNames() as $id => $name) {
            $vendors[] = ['id' => $id, 'name' => $name];
        }
        $conditions = [['id' => 'new', 'name' => 'New'], ['id' => 'used', 'name' => 'Used'], ['id' => 'refurbished', 'name' => 'Refurbished']];
        $this->fields_form = [
            'legend' => ['title' => $this->trans('Vendor offer', [], 'Modules.Bxmarketplace.Admin'), 'icon' => 'icon-tag'],
            'input' => [
                ['type' => 'select', 'label' => $this->trans('Vendor', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'id_vendor', 'required' => true, 'options' => ['query' => $vendors, 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'label' => $this->trans('Master product ID', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'id_product', 'required' => true, 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => $this->trans('Combination ID', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'id_product_attribute', 'class' => 'fixed-width-sm', 'desc' => $this->trans('0 for products without combinations.', [], 'Modules.Bxmarketplace.Admin')],
                ['type' => 'text', 'label' => $this->trans('Vendor SKU', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'vendor_sku'],
                ['type' => 'text', 'label' => 'EAN13', 'name' => 'ean13', 'maxlength' => 13],
                ['type' => 'text', 'label' => $this->trans('Price (tax excl.)', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'price', 'prefix' => '€', 'required' => true, 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => $this->trans('Discount price', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'discount_price', 'prefix' => '€', 'class' => 'fixed-width-sm'],
                ['type' => 'datetime', 'label' => $this->trans('Discount from', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'discount_from'],
                ['type' => 'datetime', 'label' => $this->trans('Discount to', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'discount_to'],
                ['type' => 'text', 'label' => $this->trans('Stock', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'quantity', 'class' => 'fixed-width-sm'],
                ['type' => 'select', 'label' => $this->trans('Condition', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'condition', 'options' => ['query' => $conditions, 'id' => 'id', 'name' => 'name']],
                ['type' => 'switch', 'label' => $this->trans('Active', [], 'Admin.Global'), 'name' => 'active', 'is_bool' => true,
                    'values' => [['id' => 'active_on', 'value' => 1, 'label' => 'Yes'], ['id' => 'active_off', 'value' => 0, 'label' => 'No']]],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ];

        return parent::renderForm();
    }

    protected function copyFromPost(&$object, $table)
    {
        parent::copyFromPost($object, $table);
        foreach (self::NULLABLE as $f) {
            $raw = trim((string) Tools::getValue($f, ''));
            $object->{$f} = $raw === '' ? null : ($f === 'discount_price' ? (float) str_replace(',', '.', $raw) : $raw);
        }
        $object->price = (float) str_replace(',', '.', (string) Tools::getValue('price', 0));
    }

    public function processSave()
    {
        foreach (['price', 'discount_price'] as $f) { // accept "4,50" as well as "4.50"
            if (isset($_POST[$f])) {
                $_POST[$f] = str_replace(',', '.', trim((string) $_POST[$f]));
            }
        }
        $idProduct = (int) Tools::getValue('id_product');
        $idAttr = (int) Tools::getValue('id_product_attribute');
        if (!$idProduct || !Product::existsInDatabase($idProduct, 'product')) {
            $this->errors[] = $this->trans('Unknown master product.', [], 'Modules.Bxmarketplace.Admin');

            return false;
        }
        if ($idAttr && !(int) Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . $idAttr . ' AND id_product = ' . $idProduct)) {
            $this->errors[] = $this->trans('The combination does not belong to the product.', [], 'Modules.Bxmarketplace.Admin');

            return false;
        }
        $existing = BxmpOffer::findId((int) Tools::getValue('id_vendor'), $idProduct, $idAttr);
        if ($existing && $existing !== (int) Tools::getValue('id_offer')) {
            $this->errors[] = $this->trans('This vendor already has an offer for this product/combination.', [], 'Modules.Bxmarketplace.Admin');

            return false;
        }
        $res = parent::processSave();
        if ($res && !empty($this->object->id)) {
            Hook::exec('actionBxmpOfferUpdated', ['id_offer' => (int) $this->object->id, 'data' => [], 'source' => 'admin']);
        }

        return $res;
    }
}

<?php
/**
 * Vendor shipping rules: country / postcode scope, exclusions, base cost, reduced & free thresholds.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

class AdminBxmpShippingRulesController extends ModuleAdminController
{
    private const NULLABLE = ['zipcode_pattern', 'free_threshold', 'reduced_threshold', 'reduced_cost'];

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_shipping_rule';
        $this->className = 'BxmpShippingRule';
        $this->identifier = 'id_shipping_rule';
        $this->lang = false;
        $this->_defaultOrderBy = 'id_vendor';

        parent::__construct();

        $this->_select = 'v.name AS vendor_name, IF(a.id_country = 0, "All", cl.name) AS country_name';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = a.id_vendor
            LEFT JOIN `' . _DB_PREFIX_ . 'country_lang` cl ON cl.id_country = a.id_country AND cl.id_lang = ' . (int) $this->context->language->id;

        $this->addRowAction('edit');
        $this->addRowAction('delete');

        $this->fields_list = [
            'id_shipping_rule' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'vendor_name' => ['title' => $this->trans('Vendor', [], 'Modules.Bxmarketplace.Admin'), 'filter_key' => 'v!name'],
            'country_name' => ['title' => $this->trans('Country', [], 'Admin.Global'), 'havingFilter' => true],
            'zipcode_pattern' => ['title' => $this->trans('Postcodes', [], 'Modules.Bxmarketplace.Admin')],
            'excluded' => ['title' => $this->trans('Excluded', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'bool', 'align' => 'center'],
            'base_cost' => ['title' => $this->trans('Base cost', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'price'],
            'reduced_threshold' => ['title' => $this->trans('Reduced from', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'price'],
            'reduced_cost' => ['title' => $this->trans('Reduced cost', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'price'],
            'free_threshold' => ['title' => $this->trans('Free from', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'price'],
            'priority' => ['title' => $this->trans('Priority', [], 'Modules.Bxmarketplace.Admin'), 'align' => 'center'],
        ];
    }

    public function renderForm()
    {
        $vendors = [];
        foreach (BxmpVendor::getNames() as $id => $name) {
            $vendors[] = ['id' => $id, 'name' => $name];
        }
        $countries = [['id_country' => 0, 'name' => $this->trans('All countries', [], 'Modules.Bxmarketplace.Admin')]];
        foreach (Country::getCountries((int) $this->context->language->id, true) as $c) {
            $countries[] = ['id_country' => (int) $c['id_country'], 'name' => $c['name']];
        }
        $money = static fn (string $name, string $label, string $desc = ''): array => ['type' => 'text', 'label' => $label, 'name' => $name, 'prefix' => '€', 'class' => 'fixed-width-sm', 'desc' => $desc];
        $this->fields_form = [
            'legend' => ['title' => $this->trans('Shipping rule', [], 'Modules.Bxmarketplace.Admin'), 'icon' => 'icon-truck'],
            'input' => [
                ['type' => 'select', 'label' => $this->trans('Vendor', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'id_vendor', 'required' => true, 'options' => ['query' => $vendors, 'id' => 'id', 'name' => 'name']],
                ['type' => 'select', 'label' => $this->trans('Country', [], 'Admin.Global'), 'name' => 'id_country', 'options' => ['query' => $countries, 'id' => 'id_country', 'name' => 'name']],
                ['type' => 'text', 'label' => $this->trans('Postcode pattern', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'zipcode_pattern',
                    'desc' => $this->trans('Comma separated prefixes and ranges, e.g. "20,21,00100-00199". Empty = all postcodes.', [], 'Modules.Bxmarketplace.Admin')],
                ['type' => 'switch', 'label' => $this->trans('Excluded destination', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'excluded', 'is_bool' => true,
                    'desc' => $this->trans('Yes = the vendor does NOT deliver to destinations matching this rule.', [], 'Modules.Bxmarketplace.Admin'),
                    'values' => [['id' => 'ex_on', 'value' => 1, 'label' => 'Yes'], ['id' => 'ex_off', 'value' => 0, 'label' => 'No']]],
                $money('base_cost', $this->trans('Base shipping cost', [], 'Modules.Bxmarketplace.Admin')),
                $money('reduced_threshold', $this->trans('Reduced shipping from (vendor subtotal)', [], 'Modules.Bxmarketplace.Admin'), $this->trans('Empty = no reduced tier.', [], 'Modules.Bxmarketplace.Admin')),
                $money('reduced_cost', $this->trans('Reduced shipping cost', [], 'Modules.Bxmarketplace.Admin')),
                $money('free_threshold', $this->trans('Free shipping from (vendor subtotal)', [], 'Modules.Bxmarketplace.Admin'), $this->trans('Empty = never free.', [], 'Modules.Bxmarketplace.Admin')),
                ['type' => 'text', 'label' => $this->trans('Priority', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'priority', 'class' => 'fixed-width-sm',
                    'desc' => $this->trans('When several rules match, the highest priority wins (then the most specific).', [], 'Modules.Bxmarketplace.Admin')],
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
            $object->{$f} = $raw === '' ? null : ($f === 'zipcode_pattern' ? $raw : (float) str_replace(',', '.', $raw));
        }
        $object->base_cost = (float) str_replace(',', '.', (string) Tools::getValue('base_cost', 0));
    }

    public function processSave()
    {
        foreach (['base_cost', 'free_threshold', 'reduced_threshold', 'reduced_cost'] as $f) { // accept "4,50" as well as "4.50"
            if (isset($_POST[$f])) {
                $_POST[$f] = str_replace(',', '.', trim((string) $_POST[$f]));
            }
        }
        if (trim((string) Tools::getValue('reduced_threshold')) !== '' && trim((string) Tools::getValue('reduced_cost')) === '') {
            $this->errors[] = $this->trans('A reduced threshold needs a reduced cost.', [], 'Modules.Bxmarketplace.Admin');

            return false;
        }

        return parent::processSave();
    }
}

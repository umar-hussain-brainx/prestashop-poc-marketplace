<?php
/**
 * Vendor users: links a PrestaShop customer account (front-office login) to a vendor with a role.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

class AdminBxmpVendorUsersController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'bxmp_vendor_user';
        $this->className = 'BxmpVendorUser';
        $this->identifier = 'id_vendor_user';
        $this->lang = false;
        $this->_defaultOrderBy = 'id_vendor_user';

        parent::__construct();

        $this->_select = 'v.name AS vendor_name, c.email AS customer_email, CONCAT(c.firstname, " ", c.lastname) AS customer_name';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = a.id_vendor
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = a.id_customer';

        $this->addRowAction('edit');
        $this->addRowAction('delete');

        $roles = array_combine(BxmpVendorUser::ROLES, BxmpVendorUser::ROLES);
        $this->fields_list = [
            'id_vendor_user' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'vendor_name' => ['title' => $this->trans('Vendor', [], 'Modules.Bxmarketplace.Admin'), 'filter_key' => 'v!name'],
            'customer_email' => ['title' => $this->trans('Customer email', [], 'Modules.Bxmarketplace.Admin'), 'filter_key' => 'c!email'],
            'customer_name' => ['title' => $this->trans('Customer', [], 'Admin.Global'), 'havingFilter' => true],
            'role' => ['title' => $this->trans('Role', [], 'Modules.Bxmarketplace.Admin'), 'type' => 'select', 'list' => $roles, 'filter_key' => 'a!role'],
            'active' => ['title' => $this->trans('Active', [], 'Admin.Global'), 'type' => 'bool', 'active' => 'status', 'align' => 'center'],
        ];
    }

    public function renderForm()
    {
        $vendors = [];
        foreach (BxmpVendor::getNames() as $id => $name) {
            $vendors[] = ['id' => $id, 'name' => $name];
        }
        $roles = array_map(static fn ($r) => ['id' => $r, 'name' => ucfirst($r)], BxmpVendorUser::ROLES);
        $this->fields_form = [
            'legend' => ['title' => $this->trans('Vendor user', [], 'Modules.Bxmarketplace.Admin'), 'icon' => 'icon-user'],
            'input' => [
                ['type' => 'select', 'label' => $this->trans('Vendor', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'id_vendor', 'required' => true, 'options' => ['query' => $vendors, 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'label' => $this->trans('Customer email', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'customer_email', 'required' => true,
                    'desc' => $this->trans('Email of an existing PrestaShop customer account; the vendor user logs in on the storefront with it.', [], 'Modules.Bxmarketplace.Admin')],
                ['type' => 'select', 'label' => $this->trans('Role', [], 'Modules.Bxmarketplace.Admin'), 'name' => 'role', 'options' => ['query' => $roles, 'id' => 'id', 'name' => 'name'],
                    'desc' => $this->trans('owner: full control incl. users & financials; manager: offers, orders, shipping; staff: orders & shipments.', [], 'Modules.Bxmarketplace.Admin')],
                ['type' => 'switch', 'label' => $this->trans('Active', [], 'Admin.Global'), 'name' => 'active', 'is_bool' => true,
                    'values' => [['id' => 'active_on', 'value' => 1, 'label' => 'Yes'], ['id' => 'active_off', 'value' => 0, 'label' => 'No']]],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ];

        return parent::renderForm();
    }

    public function getFieldsValue($obj)
    {
        $values = parent::getFieldsValue($obj);
        $values['customer_email'] = Tools::getValue('customer_email', '');
        if ($values['customer_email'] === '' && !empty($obj->id_customer)) {
            $values['customer_email'] = (new Customer((int) $obj->id_customer))->email;
        }

        return $values;
    }

    public function processSave()
    {
        $email = trim((string) Tools::getValue('customer_email'));
        $customers = Validate::isEmail($email) ? Customer::getCustomersByEmail($email) : [];
        $idCustomer = 0;
        foreach ($customers as $c) {
            if (empty($c['is_guest'])) {
                $idCustomer = (int) $c['id_customer'];
                break;
            }
        }
        if (!$idCustomer) {
            $this->errors[] = $this->trans('No registered customer account with this email.', [], 'Modules.Bxmarketplace.Admin');

            return false;
        }
        $_POST['id_customer'] = $idCustomer;
        $existing = BxmpVendorUser::findId((int) Tools::getValue('id_vendor'), $idCustomer);
        if ($existing && $existing !== (int) Tools::getValue('id_vendor_user')) {
            $this->errors[] = $this->trans('This customer is already linked to the vendor.', [], 'Modules.Bxmarketplace.Admin');

            return false;
        }

        return parent::processSave();
    }
}

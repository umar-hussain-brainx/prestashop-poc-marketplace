<?php
/**
 * Vendor portal — vendor users (owner only): list, add an existing registered customer by email with a role,
 * change role, deactivate / reactivate. Guards: no self-lockout, at least one active owner, a customer can be an
 * active member of one vendor only.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/classes/BxmpVendorPortalController.php';

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\VendorRepository;
use BrainX\MarketplaceVendor\Domain\RolePolicy;

class BxmpvendorportalUsersModuleFrontController extends BxmpVendorPortalController
{
    protected string $portalPage = 'users';
    protected string $permission = RolePolicy::USERS_MANAGE;

    protected function processPost(string $action): void
    {
        if (!in_array($action, ['add', 'update'], true)) {
            parent::processPost($action);

            return;
        }
        if (!$this->requirePermission(RolePolicy::USERS_MANAGE)) {
            return;
        }
        $action === 'add' ? $this->add() : $this->update();
        if ($this->denied === null) {
            $this->back();
        }
    }

    private function activeOwners(): int
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_vendor_user` WHERE active = 1 AND role = \'owner\' AND id_vendor = ' . $this->idVendor);
    }

    private function add(): void
    {
        $email = $this->str('email', 255);
        $role = $this->str('role', 16);
        if (!Validate::isEmail($email)) {
            $this->errors[] = 'Please enter a valid email address.';

            return;
        }
        if (!RolePolicy::canAssignRole($this->role, $role)) {
            $this->errors[] = 'Invalid role.';

            return;
        }
        $customer = new Customer();
        $customer->getByEmail($email);
        if (!Validate::isLoadedObject($customer) || $customer->is_guest || !$customer->active) {
            $this->errors[] = 'No active registered customer account uses this email. Ask the person to create a shop account first.';

            return;
        }
        $other = BxmpVendorUser::getMembershipForCustomer((int) $customer->id);
        if ($other !== null && (int) $other['id_vendor'] !== $this->idVendor) {
            $this->errors[] = 'This customer is already an active member of another vendor.';

            return;
        }
        $id = BxmpVendorUser::findId($this->idVendor, (int) $customer->id);
        $vu = new BxmpVendorUser($id ?: null);
        $vu->id_vendor = $this->idVendor;
        $vu->id_customer = (int) $customer->id;
        $vu->role = $role;
        $vu->active = true;
        if ($vu->save()) {
            Logger::channel('vendor')->info('Vendor user added from portal', ['id_vendor' => $this->idVendor, 'id_customer' => (int) $customer->id, 'role' => $role, 'by_customer' => (int) $this->context->customer->id]);
            $this->success[] = sprintf('%s now has the %s role.', $email, $role);
        } else {
            $this->errors[] = 'Could not save the user.';
        }
    }

    private function update(): void
    {
        $vu = new BxmpVendorUser((int) Tools::getValue('id_vendor_user'));
        if (!Validate::isLoadedObject($vu) || (int) $vu->id_vendor !== $this->idVendor) {
            $this->notFound();

            return;
        }
        $role = $this->str('role', 16) ?: (string) $vu->role;
        $active = (bool) Tools::getValue('active');
        if (!RolePolicy::canAssignRole($this->role, $role)) {
            $this->errors[] = 'Invalid role.';

            return;
        }
        if ((int) $vu->id_customer === (int) $this->context->customer->id && (!$active || $role !== (string) $vu->role)) {
            $this->errors[] = 'You cannot change your own role or deactivate yourself.';

            return;
        }
        if ($vu->role === RolePolicy::OWNER && $vu->active && (!$active || $role !== RolePolicy::OWNER) && $this->activeOwners() <= 1) {
            $this->errors[] = 'A vendor must keep at least one active owner.';

            return;
        }
        if ($active && !$vu->active) {
            $other = BxmpVendorUser::getMembershipForCustomer((int) $vu->id_customer);
            if ($other !== null && (int) $other['id_vendor'] !== $this->idVendor) {
                $this->errors[] = 'This customer is now an active member of another vendor.';

                return;
            }
        }
        $vu->role = $role;
        $vu->active = $active;
        if ($vu->update()) {
            Logger::channel('vendor')->info('Vendor user updated from portal', ['id_vendor' => $this->idVendor, 'id_vendor_user' => (int) $vu->id, 'role' => $role, 'active' => $active, 'by_customer' => (int) $this->context->customer->id]);
            $this->success[] = 'User updated.';
        }
    }

    protected function renderPage(): void
    {
        $this->context->smarty->assign(['usr' => [
            'users' => (new VendorRepository())->users($this->idVendor),
            'roles' => RolePolicy::ROLES,
            'me' => (int) $this->context->customer->id,
            'base_url' => $this->url('users'),
            'role_help' => [
                'owner' => 'Everything, incl. users, company / payout data and shipping rules',
                'manager' => 'Offers, inventory, price positioning, orders & shipments, analytics, CSV',
                'staff' => 'Orders & shipments only; offers read-only',
            ],
        ]]);
        $this->setTemplate('module:bxmpvendorportal/views/templates/front/users.tpl');
    }
}

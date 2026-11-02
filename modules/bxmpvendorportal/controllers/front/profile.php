<?php
/**
 * Vendor portal — commercial data (owner only): company, VAT number, payout account, contact email (editable),
 * commission terms (read-only), and own shipping rules management (base cost, free / reduced thresholds, country,
 * postcode patterns, exclusions, priority) with a live "shipping simulator" based on the core resolver.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/classes/BxmpVendorPortalController.php';

use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Shipping\ShippingRuleResolver;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\ShippingRuleRepository;
use BrainX\MarketplaceVendor\Domain\RolePolicy;
use BrainX\MarketplaceVendor\Domain\ShippingRuleInput;

class BxmpvendorportalProfileModuleFrontController extends BxmpVendorPortalController
{
    protected string $portalPage = 'profile';
    protected string $permission = RolePolicy::PROFILE_VIEW;

    private function countries(): array
    {
        $out = [];
        foreach (Country::getCountries((int) $this->context->language->id, true) as $c) {
            $out[(int) $c['id_country']] = $c['name'];
        }
        asort($out);

        return $out;
    }

    protected function processPost(string $action): void
    {
        switch ($action) {
            case 'save_profile':
                if ($this->requirePermission(RolePolicy::PROFILE_EDIT)) {
                    $this->saveProfile();
                }
                break;
            case 'save_rule':
                if ($this->requirePermission(RolePolicy::SHIPPING_EDIT)) {
                    $this->saveRule();
                }
                break;
            case 'delete_rule':
                if ($this->requirePermission(RolePolicy::SHIPPING_EDIT)) {
                    $this->deleteRule();
                }
                break;
            default:
                parent::processPost($action);
        }
    }

    private function saveProfile(): void
    {
        $data = [
            'company' => $this->str('company', 255),
            'vat_number' => strtoupper(str_replace(' ', '', $this->str('vat_number', 32))),
            'email' => $this->str('email', 255),
            'payout_account' => $this->str('payout_account', 255),
        ];
        if ($data['company'] === '' || !Validate::isGenericName($data['company'])) {
            $this->errors[] = 'Company name is required and may not contain special characters (<>={}).';
        }
        if ($data['vat_number'] !== '' && !preg_match('/^[A-Z]{2}[0-9A-Z+*.]{2,13}$/', $data['vat_number'])) {
            $this->errors[] = 'VAT number must look like IT01234567890 (country prefix + 2-13 characters).';
        }
        if ($data['email'] === '' || !Validate::isEmail($data['email'])) {
            $this->errors[] = 'A valid contact email is required.';
        }
        if ($data['payout_account'] !== '' && !Validate::isGenericName($data['payout_account'])) {
            $this->errors[] = 'Invalid payout account.';
        }
        if ($this->errors === []) {
            $vendor = new BxmpVendor($this->idVendor); // own vendor only
            foreach ($data as $k => $v) {
                $vendor->{$k} = $v === '' ? null : $v;
            }
            if ($vendor->update()) {
                Logger::channel('vendor')->info('Vendor profile updated from portal', ['id_vendor' => $this->idVendor, 'by_customer' => (int) $this->context->customer->id, 'fields' => array_keys($data)]);
                $this->success[] = 'Company information saved.';
            } else {
                $this->errors[] = 'Could not save the company information.';
            }
        }
        $this->back();
    }

    private function ownRule(int $id): ?BxmpShippingRule
    {
        if ($id <= 0) {
            return null;
        }
        $rule = new BxmpShippingRule($id);

        return (Validate::isLoadedObject($rule) && (int) $rule->id_vendor === $this->idVendor) ? $rule : null;
    }

    private function saveRule(): void
    {
        $id = (int) Tools::getValue('id_shipping_rule');
        $rule = $id > 0 ? $this->ownRule($id) : new BxmpShippingRule();
        if ($rule === null) {
            $this->notFound();

            return;
        }
        $v = ShippingRuleInput::validate($_POST, array_keys($this->countries()));
        if ($v['errors'] !== []) {
            $this->errors = $v['errors'];
            $this->back(['rule' => $id ?: 'new'] + array_intersect_key($_POST, array_flip(['id_country', 'zipcode_pattern', 'base_cost', 'free_threshold', 'reduced_threshold', 'reduced_cost', 'priority', 'excluded'])));

            return;
        }
        foreach ($v['data'] as $k => $val) {
            $rule->{$k} = $val;
        }
        $rule->id_vendor = $this->idVendor;
        if ($rule->save()) {
            Logger::channel('vendor')->info('Shipping rule saved from portal', ['id_vendor' => $this->idVendor, 'id_shipping_rule' => (int) $rule->id, 'data' => $v['data']]);
            $this->success[] = 'Shipping rule saved.';
        } else {
            $this->errors[] = 'Could not save the shipping rule.';
        }
        $this->back();
    }

    private function deleteRule(): void
    {
        $rule = $this->ownRule((int) Tools::getValue('id_shipping_rule'));
        if ($rule === null) {
            $this->notFound();

            return;
        }
        $id = (int) $rule->id;
        if ($rule->delete()) {
            Logger::channel('vendor')->info('Shipping rule deleted from portal', ['id_vendor' => $this->idVendor, 'id_shipping_rule' => $id]);
            $this->success[] = 'Shipping rule deleted.';
        }
        $this->back();
    }

    protected function renderPage(): void
    {
        $countries = $this->countries();
        $rules = BxmpShippingRule::getForVendor($this->idVendor);
        foreach ($rules as &$r) {
            $r['country_name'] = (int) $r['id_country'] === 0 ? 'All countries' : ($countries[(int) $r['id_country']] ?? ('#' . $r['id_country']));
        }
        unset($r);

        // edit form (existing rule, new, or re-display after a validation error)
        $ruleParam = (string) Tools::getValue('rule', '');
        $form = null;
        if ($ruleParam === 'new' || ctype_digit($ruleParam)) {
            $existing = ctype_digit($ruleParam) ? $this->ownRule((int) $ruleParam) : null;
            if (ctype_digit($ruleParam) && $existing === null) {
                $this->notFound();
                $this->renderDenied();

                return;
            }
            $form = $existing ? get_object_vars($existing) + ['id_shipping_rule' => (int) $existing->id] : ['id_shipping_rule' => 0, 'id_country' => 0, 'zipcode_pattern' => '', 'excluded' => 0, 'base_cost' => '', 'free_threshold' => '', 'reduced_threshold' => '', 'reduced_cost' => '', 'priority' => 0];
            foreach (['id_country', 'zipcode_pattern', 'base_cost', 'free_threshold', 'reduced_threshold', 'reduced_cost', 'priority', 'excluded'] as $k) {
                if (Tools::getIsset($k)) {
                    $form[$k] = $this->str($k, 255);
                }
            }
        }

        // simulator
        $sim = null;
        if (Tools::getIsset('sim_country')) {
            $policy = (new ShippingRuleRepository())->policiesFor([$this->idVendor])[$this->idVendor] ?? null;
            $dest = new Destination((int) Tools::getValue('sim_country'), $this->str('sim_zip', 16));
            $subtotal = max(0.0, (float) str_replace(',', '.', (string) Tools::getValue('sim_subtotal', '0')));
            $cost = (new ShippingRuleResolver())->costFor($policy ? $policy->rules : [], $dest, $subtotal);
            $sim = ['country' => (int) Tools::getValue('sim_country'), 'zip' => $this->str('sim_zip', 16), 'subtotal' => $subtotal,
                'deliverable' => $cost !== null, 'cost' => $cost === null ? null : $this->money($cost)];
        }

        $vendor = new BxmpVendor($this->idVendor);
        $this->context->smarty->assign(['prof' => [
            'vendor' => [
                'name' => $vendor->name, 'code' => $vendor->code, 'company' => $vendor->company, 'vat_number' => $vendor->vat_number,
                'email' => $vendor->email, 'payout_account' => $vendor->payout_account,
                'commission_rate' => rtrim(rtrim(number_format((float) $vendor->commission_rate, 2), '0'), '.'),
                'commission_fixed' => $this->money((float) $vendor->commission_fixed),
                'commission_on_shipping' => (bool) Configuration::get('BXMP_COMMISSION_ON_SHIPPING'),
            ],
            'rules' => $rules,
            'form' => $form,
            'countries' => $countries,
            'sim' => $sim,
            'default_country' => (int) Configuration::get('PS_COUNTRY_DEFAULT'),
            'base_url' => $this->url('profile'),
        ]]);
        $this->setTemplate('module:bxmpvendorportal/views/templates/front/profile.tpl');
    }
}

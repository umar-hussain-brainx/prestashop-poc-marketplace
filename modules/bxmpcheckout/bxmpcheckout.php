<?php
/**
 * BrainX Marketplace — multi-vendor shipping & checkout (scope modules 4 storefront, 5 integration, 6).
 *
 *  - carrier module "Marketplace multi-vendor shipping": shipping = Σ per-vendor shipping of the optimal allocation;
 *  - CartOptimizer integration: cart lines + destination → allocation → cart-scoped SpecificPrice per line, so the
 *    native PrestaShop cart / order totals equal the optimized totals (no core change, no override);
 *  - storefront: vendor offers comparison on the product page, "optimized allocation" block in the cart;
 *  - order split on actionValidateOrder: bxmp_vendor_order (+lines) per vendor, commissions, payment allocation
 *    through a PaymentSplitter (Mangopay sandbox simulation / null), per-vendor tracking in FO and BO.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Shipping\ShippingRuleResolver;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\CartAllocationRepository;
use BrainX\Marketplace\Infrastructure\Repository\ShippingRuleRepository;
use BrainX\Marketplace\Infrastructure\Service\CartOptimizationService;
use BrainX\Marketplace\Infrastructure\Service\PriceComparisonService;
use BrainX\MarketplaceCheckout\Carrier\CarrierInstaller;
use BrainX\MarketplaceCheckout\Checkout\AllocationPresenter;
use BrainX\MarketplaceCheckout\Checkout\CartSynchronizer;
use BrainX\MarketplaceCheckout\Checkout\SyncState;
use BrainX\MarketplaceCheckout\Order\VendorOrderCreator;
use BrainX\MarketplaceCheckout\Payment\Mangopay\MangopayConfig;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitterFactory;

class Bxmpcheckout extends CarrierModule
{
    public const HOOKS = [
        'actionCartSave',
        'actionFrontControllerSetMedia',
        'actionPresentCart',
        'actionFilterDeliveryOptionList',
        'actionValidateStepComplete',
        'actionValidateOrder',
        'actionCarrierUpdate',
        'displayProductAdditionalInfo',
        'displayShoppingCartFooter',
        'displayBeforeCarrier',
        'displayOrderConfirmation',
        'displayOrderDetail',
        'displayAdminOrderMain',
    ];

    public const CONFIG_DEFAULTS = [
        'BXMPCO_EXCLUSIVE_CARRIER' => 1,
        'BXMPCO_PAYMENT_SPLITTER' => 'mangopay',
        'BXMPCO_MANGOPAY_CLIENT_ID' => 'brainx-poc',
        'BXMPCO_MANGOPAY_MODE' => MangopayConfig::MODE_SIMULATION,
        'BXMPCO_MANGOPAY_ESCROW_WALLET' => '',
        'BXMPCO_MANGOPAY_ESCROW_OWNER' => '',
        'BXMPCO_SHOW_ALTERNATIVES' => 1,
    ];

    public const ADMIN_TAB = 'AdminBxmpCheckoutVendorOrders';

    /** @var int|null set by PrestaShop before getOrderShippingCost() */
    public $id_carrier;

    public function __construct()
    {
        $this->name = 'bxmpcheckout';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'BrainX';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['bxmarketplace'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('BrainX Marketplace — multi-vendor shipping & checkout', [], 'Modules.Bxmpcheckout.Admin');
        $this->description = $this->trans('Marketplace carrier (per-vendor shipping), smart cart optimizer pricing, vendor offer comparison, order split, commissions and payment allocation (Mangopay).', [], 'Modules.Bxmpcheckout.Admin');
    }

    // ------------------------------------------------------------------ install / uninstall

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        foreach (self::CONFIG_DEFAULTS as $k => $v) {
            if (Configuration::get($k) === false) {
                Configuration::updateValue($k, $v);
            }
        }

        return $this->registerHook(self::HOOKS)
            && (new CarrierInstaller($this->name))->install() > 0
            && $this->installTab();
    }

    public function uninstall()
    {
        (new CarrierInstaller($this->name))->uninstall();
        $id = (int) Tab::getIdFromClassName(self::ADMIN_TAB);
        if ($id) {
            (new Tab($id))->delete();
        }
        foreach (array_keys(self::CONFIG_DEFAULTS) as $k) {
            Configuration::deleteByName($k);
        }
        // cart-scoped prices of carts that never became an order
        Db::getInstance()->execute('DELETE sp FROM `' . _DB_PREFIX_ . 'specific_price` sp INNER JOIN `' . _DB_PREFIX_ . 'bxmp_cart_allocation` ca ON ca.id_cart = sp.id_cart WHERE sp.id_specific_price_rule = 0');

        return parent::uninstall();
    }

    private function installTab(): bool
    {
        $id = (int) Tab::getIdFromClassName(self::ADMIN_TAB);
        $tab = new Tab($id ?: null);
        $tab->class_name = self::ADMIN_TAB;
        $tab->module = $this->name;
        $tab->active = false; // action endpoint only (vendor sub-order status / tracking from the BO order page)
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminBxMarketplace');
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Vendor sub-orders';
        }

        return $tab->save();
    }

    public static function marketplaceCarrierId(): int
    {
        return CarrierInstaller::currentCarrierId();
    }

    // ------------------------------------------------------------------ carrier module API

    /**
     * Shipping of the whole cart = Σ per-vendor shipping of the optimal allocation for the delivery address
     * (country + postcode). false = carrier unavailable (a product cannot be supplied → checkout blocked).
     *
     * @param Cart  $params
     * @param float $shipping_cost core range price (always 0, ignored)
     */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        $cart = $params instanceof Cart ? $params : $this->context->cart;
        if (!$cart instanceof Cart || !$cart->id) {
            return false;
        }
        $state = (new CartSynchronizer())->sync($cart);

        return match ($state->status) {
            SyncState::OK => (float) round($state->result->shippingTotal, 6),
            SyncState::EMPTY => 0.0,
            SyncState::ORDERED => (new CartAllocationRepository())->totalShipping((int) $cart->id),
            default => false,
        };
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    public function hookActionCarrierUpdate($params)
    {
        // PS clones a carrier on edit: keep following its id_reference (nothing to do, we resolve by reference).
        if (isset($params['carrier']) && (int) $params['carrier']->id_reference === (int) Configuration::getGlobalValue(CarrierInstaller::CONFIG_REFERENCE)) {
            Logger::channel('checkout')->info('Marketplace carrier updated in BO', ['id_carrier' => (int) $params['carrier']->id]);
        }
    }

    /** Marketplace carts can only be shipped by the marketplace carrier (other carriers would ignore vendor rules). */
    public function hookActionFilterDeliveryOptionList($params)
    {
        if (!Configuration::get('BXMPCO_EXCLUSIVE_CARRIER') || !isset($params['delivery_option_list'])) {
            return;
        }
        $idCarrier = self::marketplaceCarrierId();
        foreach ($params['delivery_option_list'] as $idAddress => $options) {
            foreach ($options as $key => $option) {
                $carriers = array_map('intval', array_keys($option['carrier_list'] ?? []));
                if ($carriers !== [$idCarrier]) {
                    unset($params['delivery_option_list'][$idAddress][$key]);
                }
            }
        }
    }

    // ------------------------------------------------------------------ cart optimisation integration

    public function hookActionCartSave($params)
    {
        $cart = $params['cart'] ?? null;
        if (!$cart instanceof Cart || !$cart->id) {
            return;
        }
        (new CartSynchronizer())->sync($cart);
    }

    /** Re-price the current cart at the start of every storefront request (offer prices / stock may have changed). */
    public function hookActionFrontControllerSetMedia($params)
    {
        $page = (string) ($this->context->controller->php_self ?? '');
        if (in_array($page, ['product', 'cart', 'order', 'order-confirmation', 'order-detail', 'history'], true)) {
            $this->context->controller->registerStylesheet('bxmpco-front', 'modules/' . $this->name . '/views/css/front.css', ['media' => 'all', 'priority' => 200]);
        }
        $cart = $this->context->cart;
        if ($page !== 'order-confirmation' && $cart instanceof Cart && $cart->id && $cart->nbProducts() > 0) {
            (new CartSynchronizer())->sync($cart);
        }
    }

    /** Blocks "Proceed to checkout" (and redirects the checkout back to the cart) when a product cannot be supplied. */
    public function hookActionPresentCart($params)
    {
        $cart = $this->context->cart;
        if (!$cart instanceof Cart || !$cart->id || !isset($params['presentedCart'])) {
            return;
        }
        $state = CartSynchronizer::lastState((int) $cart->id) ?? (new CartSynchronizer())->sync($cart);
        if ($state->isBlocking() && $params['presentedCart'] instanceof PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartLazyArray) {
            $params['presentedCart']->offsetSet('minimalPurchaseRequired', $state->blockingMessage(), true);
        }
    }

    public function hookActionValidateStepComplete($params)
    {
        if (($params['step_name'] ?? '') !== 'delivery') {
            return;
        }
        $cart = $this->context->cart;
        $state = (new CartSynchronizer())->sync($cart);
        if ($state->isBlocking()) {
            $params['completed'] = false;
            $this->context->controller->errors[] = $state->blockingMessage();
        }
    }

    // ------------------------------------------------------------------ order split

    public function hookActionValidateOrder($params)
    {
        $order = $params['order'] ?? null;
        if (!$order instanceof Order || !Validate::isLoadedObject($order)) {
            return;
        }
        try {
            $creator = new VendorOrderCreator($this->paymentSplitter(), self::marketplaceCarrierId());
            $creator->createForOrder($order);
        } catch (Throwable $e) {
            // never break the customer's order: log and let the admin re-run the split
            Logger::channel('checkout')->error('Order split failed: ' . $e->getMessage(), ['id_order' => (int) $order->id, 'file' => $e->getFile() . ':' . $e->getLine()]);
        }
    }

    public function paymentSplitter(): BrainX\MarketplaceCheckout\Payment\PaymentSplitter
    {
        return PaymentSplitterFactory::create([
            'splitter' => (string) Configuration::get('BXMPCO_PAYMENT_SPLITTER'),
            'mangopay_client_id' => (string) Configuration::get('BXMPCO_MANGOPAY_CLIENT_ID'),
            'mangopay_mode' => (string) Configuration::get('BXMPCO_MANGOPAY_MODE'),
            'mangopay_escrow_wallet' => (string) Configuration::get('BXMPCO_MANGOPAY_ESCROW_WALLET'),
            'mangopay_escrow_owner' => (string) Configuration::get('BXMPCO_MANGOPAY_ESCROW_OWNER'),
        ]);
    }

    // ------------------------------------------------------------------ storefront display

    public function hookDisplayProductAdditionalInfo($params)
    {
        $product = $params['product'] ?? null;
        if (!$product) {
            return '';
        }
        $idProduct = (int) $product['id_product'];
        $idAttr = (int) ($product['id_product_attribute'] ?? 0);
        $dest = $this->contextDestination();
        $service = new PriceComparisonService();
        $rows = $service->compareProduct($idProduct, $idAttr, 1, $dest)[$idAttr] ?? [];
        $policies = (new ShippingRuleRepository())->policiesFor(array_values(array_unique(array_map(static fn ($r) => $r->offer->idVendor, $rows))) ?: [0]);
        $resolver = new ShippingRuleResolver();
        $taxRate = $this->displayTaxRate($idProduct);
        $money = $this->moneyFormatter();
        $offers = [];
        foreach ($rows as $r) {
            $policy = $policies[$r->offer->idVendor] ?? null;
            $rule = $resolver->effectiveRule($policy ? $policy->rules : [], $dest, $r->offer->idVendor);
            $offers[] = [
                'vendor' => $r->offer->vendorName ?? ('Vendor #' . $r->offer->idVendor),
                'condition' => $r->offer->condition,
                'price_fmt' => $money($r->offer->price * (1 + $taxRate)),
                'effective_fmt' => $money($r->effectivePrice * (1 + $taxRate)),
                'on_discount' => $r->onDiscount,
                'discount_to' => $r->onDiscount && $r->offer->discountTo ? $r->offer->discountTo->format('d/m/Y') : null,
                'stock' => $r->offer->quantity,
                'available' => $r->available,
                'deliverable' => $r->deliverable,
                'shipping_rule' => $rule ? AllocationPresenter::ruleSummary($rule, $money) : 'No delivery to your address',
                'shipping_fmt' => $r->shippingCost === null ? '—' : ($r->shippingCost <= 0 ? 'Free' : $money($r->shippingCost)),
                'landed_fmt' => $money($r->effectivePrice * (1 + $taxRate) + (float) $r->shippingCost),
                'best' => $r->best,
                'rank' => $r->rank,
                'reason' => $r->ineligibleReason ? str_replace('_', ' ', $r->ineligibleReason) : null,
            ];
        }
        $this->context->smarty->assign([
            'bxmpco_offers' => $offers,
            'bxmpco_destination' => $this->destinationLabel($dest),
            'bxmpco_tax_label' => $taxRate > 0 ? 'tax incl.' : 'tax excl.',
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/product_offers.tpl');
    }

    public function hookDisplayShoppingCartFooter($params)
    {
        return $this->renderAllocation('full');
    }

    public function hookDisplayBeforeCarrier($params)
    {
        return $this->renderAllocation('compact');
    }

    private function renderAllocation(string $variant): string
    {
        $cart = $this->context->cart;
        if (!$cart instanceof Cart || !$cart->id || $cart->nbProducts() <= 0) {
            return '';
        }
        $state = CartSynchronizer::lastState((int) $cart->id) ?? (new CartSynchronizer())->sync($cart);
        $view = null;
        if ($state->status === SyncState::OK && $state->result) {
            $names = [];
            foreach ($cart->getProducts() as $p) {
                $names[(int) $p['id_product'] . '-' . (int) $p['id_product_attribute']] = $p['name'] . (!empty($p['attributes_small']) ? ' (' . $p['attributes_small'] . ')' : '');
            }
            $policies = (new ShippingRuleRepository())->policiesFor(array_map(static fn ($v) => $v->idVendor, $state->result->vendors));
            $view = (new AllocationPresenter($this->moneyFormatter()))->present($state->result, $policies, $state->destination, $names);
        }
        $this->context->smarty->assign([
            'bxmpco_variant' => $variant,
            'bxmpco_state' => $state->status,
            'bxmpco_block_message' => $state->blockingMessage(),
            'bxmpco_alloc' => $view,
            'bxmpco_destination' => $state->destination ? $this->destinationLabel($state->destination) : '',
            'bxmpco_show_alternatives' => (bool) Configuration::get('BXMPCO_SHOW_ALTERNATIVES'),
            'bxmpco_has_tax' => $this->cartHasTax($cart),
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/cart_allocation.tpl');
    }

    public function hookDisplayOrderConfirmation($params)
    {
        $order = $params['order'] ?? null;

        return $order instanceof Order ? $this->renderOrderSplit((int) $order->id, 'confirmation') : '';
    }

    public function hookDisplayOrderDetail($params)
    {
        $order = $params['order'] ?? null;

        return $order instanceof Order ? $this->renderOrderSplit((int) $order->id, 'detail') : '';
    }

    /** @return array<int, array> vendor orders of a PS order with lines and vendor names */
    public function vendorOrdersFor(int $idOrder): array
    {
        $money = $this->moneyFormatter();
        $rows = Db::getInstance()->executeS('SELECT vo.*, v.name AS vendor_name, v.payout_account FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` vo
            LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = vo.id_vendor WHERE vo.id_order = ' . (int) $idOrder . ' ORDER BY vo.id_vendor') ?: [];
        foreach ($rows as &$r) {
            $r['lines'] = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order_line` WHERE id_vendor_order = ' . (int) $r['id_vendor_order']) ?: [];
            foreach ($r['lines'] as &$l) {
                $l['unit_price_fmt'] = $money((float) $l['unit_price']);
                $l['total_fmt'] = $money((float) $l['unit_price'] * (int) $l['quantity']);
                $l['commission_fmt'] = $money((float) $l['commission_amount']);
            }
            unset($l);
            foreach (['total_products', 'total_shipping', 'total_paid', 'commission_amount', 'vendor_payout'] as $f) {
                $r[$f . '_fmt'] = $money((float) $r[$f]);
            }
            $r['shipping_label'] = (float) $r['total_shipping'] <= 0 ? 'Free' : $r['total_shipping_fmt'];
            $r['commission_rate'] = (float) $r['total_products'] > 0 ? round((float) $r['commission_amount'] / (float) $r['total_products'] * 100, 2) : 0;
        }
        unset($r);

        return $rows;
    }

    private function renderOrderSplit(int $idOrder, string $variant): string
    {
        $vendorOrders = $this->vendorOrdersFor($idOrder);
        if ($vendorOrders === []) {
            return '';
        }
        $this->context->smarty->assign(['bxmpco_vendor_orders' => $vendorOrders, 'bxmpco_variant' => $variant]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/order_split.tpl');
    }

    public function hookDisplayAdminOrderMain($params)
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $vendorOrders = $this->vendorOrdersFor($idOrder);
        $order = new Order($idOrder);
        $this->context->smarty->assign([
            'bxmpco_vendor_orders' => $vendorOrders,
            'bxmpco_statuses' => BxmpVendorOrder::STATUSES,
            'bxmpco_action' => $this->context->link->getAdminLink(self::ADMIN_TAB),
            'bxmpco_id_order' => $idOrder,
            'bxmpco_updated' => (bool) Tools::getValue('bxmpco_updated'),
            'bxmpco_is_marketplace' => (int) $order->id_carrier === self::marketplaceCarrierId(),
            'bxmpco_totals' => [
                'paid' => $this->moneyFormatter()(array_sum(array_map(static fn ($v) => (float) $v['total_paid'], $vendorOrders))),
                'commission' => $this->moneyFormatter()(array_sum(array_map(static fn ($v) => (float) $v['commission_amount'], $vendorOrders))),
                'payout' => $this->moneyFormatter()(array_sum(array_map(static fn ($v) => (float) $v['vendor_payout'], $vendorOrders))),
            ],
        ]);

        return $this->display(__FILE__, 'views/templates/admin/order_vendor_split.tpl');
    }

    // ------------------------------------------------------------------ helpers

    private function contextDestination(): Destination
    {
        $cart = $this->context->cart;
        if ($cart instanceof Cart && ($cart->id_address_delivery || $cart->id_customer)) {
            return CartSynchronizer::destinationFor($cart);
        }
        if ($this->context->customer && $this->context->customer->isLogged()) {
            return CartOptimizationService::destinationFromAddress((int) Address::getFirstCustomerAddressId((int) $this->context->customer->id));
        }

        return CartOptimizationService::destinationFromAddress(0);
    }

    private function destinationLabel(Destination $d): string
    {
        $country = Country::getNameById((int) $this->context->language->id, $d->countryId) ?: ($d->countryIso ?? '');

        return trim($d->postcode . ' ' . $country);
    }

    /** Product tax rate (fraction) when prices are displayed tax included, 0 otherwise. */
    private function displayTaxRate(int $idProduct): float
    {
        if (!Configuration::get('PS_TAX') || Product::getTaxCalculationMethod((int) ($this->context->customer->id ?? 0)) == PS_TAX_EXC) {
            return 0.0;
        }
        $idAddress = $this->context->cart && $this->context->cart->id_address_delivery ? (int) $this->context->cart->id_address_delivery : null;

        return (float) Tax::getProductTaxRate($idProduct, $idAddress) / 100;
    }

    private function cartHasTax(Cart $cart): bool
    {
        return abs($cart->getOrderTotal(true, Cart::ONLY_PRODUCTS) - $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS)) > 0.001;
    }

    /** @return callable(float):string */
    public function moneyFormatter(): callable
    {
        $context = $this->context;

        return static function (float $v) use ($context): string {
            try {
                $iso = $context->currency ? $context->currency->iso_code : 'EUR';

                return Tools::getContextLocale($context)->formatPrice($v, $iso);
            } catch (Throwable) {
                return '€' . number_format($v, 2, '.', ',');
            }
        };
    }

    // ------------------------------------------------------------------ configuration page

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitBxmpcheckout')) {
            Configuration::updateValue('BXMPCO_EXCLUSIVE_CARRIER', (int) Tools::getValue('BXMPCO_EXCLUSIVE_CARRIER'));
            Configuration::updateValue('BXMPCO_SHOW_ALTERNATIVES', (int) Tools::getValue('BXMPCO_SHOW_ALTERNATIVES'));
            $splitter = (string) Tools::getValue('BXMPCO_PAYMENT_SPLITTER');
            Configuration::updateValue('BXMPCO_PAYMENT_SPLITTER', in_array($splitter, PaymentSplitterFactory::SPLITTERS, true) ? $splitter : 'mangopay');
            foreach (['BXMPCO_MANGOPAY_CLIENT_ID', 'BXMPCO_MANGOPAY_ESCROW_WALLET', 'BXMPCO_MANGOPAY_ESCROW_OWNER'] as $k) {
                Configuration::updateValue($k, trim((string) Tools::getValue($k)));
            }
            $out .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Notifications.Success'));
        }
        if (Tools::isSubmit('submitBxmpcheckoutCarrier')) {
            $id = (new CarrierInstaller($this->name))->install();
            $out .= $id ? $this->displayConfirmation('Marketplace carrier restored (#' . $id . ').') : $this->displayError('Could not restore the carrier.');
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitBxmpcheckout';
        $helper->fields_value = [];
        foreach (array_keys(self::CONFIG_DEFAULTS) as $k) {
            $helper->fields_value[$k] = Configuration::get($k);
        }
        $switch = static fn (string $name, string $label, string $desc) => ['type' => 'switch', 'name' => $name, 'label' => $label, 'desc' => $desc, 'is_bool' => true,
            'values' => [['id' => $name . '_on', 'value' => 1, 'label' => 'Yes'], ['id' => $name . '_off', 'value' => 0, 'label' => 'No']]];
        $form = ['form' => [
            'legend' => ['title' => 'Marketplace checkout', 'icon' => 'icon-truck'],
            'description' => sprintf('Marketplace carrier: #%d "%s". Shipping = sum of the per-vendor shipping of the optimal allocation.', self::marketplaceCarrierId(), CarrierInstaller::NAME),
            'input' => [
                $switch('BXMPCO_EXCLUSIVE_CARRIER', 'Marketplace carrier only', 'Hide every other carrier on marketplace carts (other carriers ignore vendor shipping rules).'),
                $switch('BXMPCO_SHOW_ALTERNATIVES', 'Show alternatives in cart', 'Show the alternatives evaluated by the optimizer and the savings.'),
                ['type' => 'select', 'name' => 'BXMPCO_PAYMENT_SPLITTER', 'label' => 'Payment splitter', 'options' => ['query' => [
                    ['id' => 'mangopay', 'name' => 'Mangopay (sandbox simulation)'], ['id' => 'null', 'name' => 'None (pay vendors via settlements)']], 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'name' => 'BXMPCO_MANGOPAY_CLIENT_ID', 'label' => 'Mangopay ClientId'],
                ['type' => 'text', 'name' => 'BXMPCO_MANGOPAY_ESCROW_WALLET', 'label' => 'Escrow wallet id', 'desc' => 'Empty = simulated buyer wallet per customer.'],
                ['type' => 'text', 'name' => 'BXMPCO_MANGOPAY_ESCROW_OWNER', 'label' => 'Escrow wallet owner (user id)'],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            'buttons' => [['type' => 'submit', 'name' => 'submitBxmpcheckoutCarrier', 'title' => 'Re-create / repair carrier', 'icon' => 'process-icon-refresh', 'class' => 'btn btn-default pull-right']],
        ]];

        return $out . $helper->generateForm([$form]);
    }
}

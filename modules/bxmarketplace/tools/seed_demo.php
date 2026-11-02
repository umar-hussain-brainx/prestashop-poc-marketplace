<?php
/**
 * BrainX Marketplace — idempotent demo data seeder (CLI only).
 *
 *   docker compose exec -T -u www-data prestashop php modules/bxmarketplace/tools/seed_demo.php
 *
 * Creates / updates:
 *  - 3 vendors (Vendor A 10 %, Vendor B 12.5 %, Vendor C 8 % + €0.50/line) with a storefront owner login each
 *    (vendor-a@marketplace.test ... / Vendor123!)
 *  - vendor shipping rules: A €5 free ≥ €30; B €5 free ≥ €20; C €6.90, €2.90 ≥ €25, free ≥ €60, no delivery to
 *    IT postcodes starting with "09" (Sardinia example)
 *  - master products "Product 1" (EAN 8000000000011) and "Product 2" (EAN 8000000000028) with the client
 *    reference-scenario offers (A: €5 / €40, B: €20 / €25; C: €22 / €45 — worse)
 *  - EANs + multi-vendor offers on 10 PrestaShop demo products (all combinations)
 *  - API tokens (admin + one per vendor) and a test customer customer@marketplace.test / Customer123! (Milan 20121)
 * Re-running is safe: everything is matched by code / email / EAN / label and updated in place.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$psRoot = dirname(__DIR__, 3);
require_once $psRoot . '/config/config.inc.php';

if (!defined('_PS_VERSION_')) {
    exit("PrestaShop bootstrap failed\n");
}

use BrainX\Marketplace\Domain\Optimizer\BasketLine;
use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Matching\MatchingEngine;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\ApiTokenRepository;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;
use BrainX\Marketplace\Infrastructure\Service\CartOptimizationService;
use PrestaShop\PrestaShop\Adapter\ServiceLocator;

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Hint: run as www-data (docker compose exec -u www-data ...) so PrestaShop cache files keep the right owner.\n");
}

/** @var Bxmarketplace|false $module */
$module = Module::getInstanceByName('bxmarketplace');
if (!$module) {
    exit("Module bxmarketplace not found\n");
}
if (!Module::isInstalled('bxmarketplace')) {
    echo "Installing bxmarketplace...\n";
    if (!$module->install()) {
        exit('Install failed: ' . implode(', ', $module->getErrors()) . "\n");
    }
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

$context = Context::getContext();
if (!$context->employee) {
    $context->employee = new Employee((int) Db::getInstance()->getValue('SELECT id_employee FROM `' . _DB_PREFIX_ . 'employee` WHERE active = 1 ORDER BY id_employee'));
}
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$idShop = (int) $context->shop->id;
$idIT = (int) Country::getByIso('IT');
$languages = Language::getLanguages(false);
$hasher = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');

$out = static function (string $line): void {
    echo $line, "\n";
};
$langField = static function (string $value) use ($languages): array {
    $r = [];
    foreach ($languages as $l) {
        $r[(int) $l['id_lang']] = $value;
    }

    return $r;
};
$ean = static function (string $first12): string {
    return $first12 . MatchingEngine::ean13CheckDigit($first12);
};

/** Find or create a registered customer. */
$ensureCustomer = static function (string $email, string $password, string $first, string $last) use ($hasher): Customer {
    $c = new Customer();
    $c->getByEmail($email);
    if (!Validate::isLoadedObject($c)) {
        $c = new Customer();
        $c->email = $email;
        $c->firstname = $first;
        $c->lastname = $last;
        $c->id_gender = 1;
        $c->active = true;
        $c->is_guest = false;
        $c->newsletter = false;
        $c->optin = false;
        $c->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
    }
    $c->passwd = $hasher->hash($password);
    $c->firstname = $first;
    $c->lastname = $last;
    $c->active = true;
    $c->id ? $c->update() : $c->add();

    return $c;
};

$db = Db::getInstance();
$offers = new OfferRepository();

// ------------------------------------------------------------------ vendors, logins, shipping rules
$vendorDefs = [
    'vendor-a' => ['name' => 'Vendor A', 'company' => 'Vendor A S.r.l.', 'vat' => 'IT01234560011', 'rate' => 10.0, 'fixed' => 0.0, 'login' => 'vendor-a@marketplace.test', 'last' => 'A',
        'rules' => [['id_country' => 0, 'zipcode_pattern' => null, 'excluded' => 0, 'base_cost' => 5.0, 'free_threshold' => 30.0, 'reduced_threshold' => null, 'reduced_cost' => null, 'priority' => 0]]],
    'vendor-b' => ['name' => 'Vendor B', 'company' => 'Vendor B S.p.A.', 'vat' => 'IT01234560022', 'rate' => 12.5, 'fixed' => 0.0, 'login' => 'vendor-b@marketplace.test', 'last' => 'B',
        'rules' => [['id_country' => 0, 'zipcode_pattern' => null, 'excluded' => 0, 'base_cost' => 5.0, 'free_threshold' => 20.0, 'reduced_threshold' => null, 'reduced_cost' => null, 'priority' => 0]]],
    'vendor-c' => ['name' => 'Vendor C', 'company' => 'Vendor C S.n.c.', 'vat' => 'IT01234560033', 'rate' => 8.0, 'fixed' => 0.5, 'login' => 'vendor-c@marketplace.test', 'last' => 'C',
        'rules' => [
            ['id_country' => 0, 'zipcode_pattern' => null, 'excluded' => 0, 'base_cost' => 6.90, 'free_threshold' => 60.0, 'reduced_threshold' => 25.0, 'reduced_cost' => 2.90, 'priority' => 0],
            ['id_country' => $idIT, 'zipcode_pattern' => '09', 'excluded' => 1, 'base_cost' => 0.0, 'free_threshold' => null, 'reduced_threshold' => null, 'reduced_cost' => null, 'priority' => 10],
        ]],
];
$vendorIds = [];
foreach ($vendorDefs as $code => $d) {
    $id = BxmpVendor::getIdByCode($code);
    $v = new BxmpVendor($id ?: null);
    $v->name = $d['name'];
    $v->code = $code;
    $v->email = $d['login'];
    $v->company = $d['company'];
    $v->vat_number = $d['vat'];
    $v->commission_rate = $d['rate'];
    $v->commission_fixed = $d['fixed'];
    $v->payout_account = 'mangopay-sandbox-wallet-' . $code;
    $v->active = true;
    $v->save();
    $vendorIds[$code] = (int) $v->id;

    $customer = $ensureCustomer($d['login'], 'Vendor123!', 'Vendor', $d['last']);
    $vuId = BxmpVendorUser::findId((int) $v->id, (int) $customer->id);
    $vu = new BxmpVendorUser($vuId ?: null);
    $vu->id_vendor = (int) $v->id;
    $vu->id_customer = (int) $customer->id;
    $vu->role = BxmpVendorUser::ROLE_OWNER;
    $vu->active = true;
    $vu->save();

    // update rules in place (stable ids), drop extras
    $existingRules = array_map('intval', array_column($db->executeS('SELECT id_shipping_rule FROM `' . _DB_PREFIX_ . 'bxmp_shipping_rule` WHERE id_vendor = ' . (int) $v->id . ' ORDER BY id_shipping_rule') ?: [], 'id_shipping_rule'));
    foreach ($d['rules'] as $i => $r) {
        $rule = new BxmpShippingRule($existingRules[$i] ?? null);
        foreach ($r as $k => $val) {
            $rule->{$k} = $val;
        }
        $rule->id_vendor = (int) $v->id;
        $rule->id ? $rule->update(true) : $rule->add(true, true);
    }
    foreach (array_slice($existingRules, count($d['rules'])) as $extra) {
        $db->delete('bxmp_shipping_rule', 'id_shipping_rule = ' . $extra);
    }
    $out(sprintf('Vendor %-9s id=%d commission=%s%%%s login=%s', $d['name'], $v->id, $d['rate'], $d['fixed'] ? ' + €' . number_format($d['fixed'], 2) . '/line' : '', $d['login']));
}

// ------------------------------------------------------------------ master products of the client scenario
$homeCategory = (int) Configuration::get('PS_HOME_CATEGORY');
$ensureMasterProduct = static function (string $name, string $ean13, string $reference, float $price) use ($langField, $homeCategory, $idShop): int {
    $id = (int) Product::getIdByEan13($ean13);
    $p = new Product($id ?: null, false, null, $idShop);
    $p->name = $langField($name);
    $p->link_rewrite = $langField(Tools::str2url($name));
    $p->description_short = $langField('<p>Marketplace master product used by the client reference scenario (Smart Cart Optimizer).</p>');
    $p->ean13 = $ean13;
    $p->reference = $reference;
    $p->price = $price;
    $p->id_tax_rules_group = 0;
    $p->id_category_default = $homeCategory;
    $p->active = true;
    $p->visibility = 'both';
    $p->available_for_order = true;
    $p->show_price = true;
    $p->state = Product::STATE_SAVED;
    $p->id ? $p->update() : $p->add();
    $p->updateCategories([$homeCategory]);

    return (int) $p->id;
};
$p1 = $ensureMasterProduct('Product 1', '8000000000011', 'BXMP-P1', 5.0);
$p2 = $ensureMasterProduct('Product 2', '8000000000028', 'BXMP-P2', 25.0);

$scenario = [
    ['vendor-a', $p1, 5.00, '8000000000011'], ['vendor-a', $p2, 40.00, '8000000000028'],
    ['vendor-b', $p1, 20.00, '8000000000011'], ['vendor-b', $p2, 25.00, '8000000000028'],
    ['vendor-c', $p1, 22.00, '8000000000011'], ['vendor-c', $p2, 45.00, '8000000000028'],
];
foreach ($scenario as [$code, $idProduct, $price, $e]) {
    $offers->upsert([
        'id_vendor' => $vendorIds[$code], 'id_product' => $idProduct, 'id_product_attribute' => 0,
        'vendor_sku' => strtoupper($code) . '-' . ($idProduct === $p1 ? 'P1' : 'P2'), 'ean13' => $e,
        'price' => $price, 'discount_price' => null, 'discount_from' => null, 'discount_to' => null,
        'quantity' => 50, 'condition' => 'new', 'active' => true,
    ]);
}
foreach ([$p1, $p2] as $idProduct) {
    StockAvailable::setQuantity($idProduct, 0, $offers->totalStock($idProduct, 0), $idShop);
}
$out(sprintf('Master products: Product 1 id=%d (EAN 8000000000011), Product 2 id=%d (EAN 8000000000028)', $p1, $p2));

// ------------------------------------------------------------------ EANs + offers on existing PS demo products
$demo = $db->executeS('SELECT p.id_product, p.price, pl.name FROM `' . _DB_PREFIX_ . 'product` p
    INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
    WHERE p.active = 1 AND p.is_virtual = 0 AND p.customizable = 0 AND p.cache_is_pack = 0 AND p.reference LIKE \'demo\\_%\'
    ORDER BY p.id_product LIMIT 10') ?: [];
$factor = static function (string $code, int $idProduct, int $idAttr): float {
    $h = crc32($code . ':' . $idProduct . ':' . $idAttr);

    return 0.86 + ($h % 27) / 100; // 0.86 .. 1.12
};
$stock = static function (string $code, int $idProduct, int $idAttr): int {
    return 3 + crc32('s' . $code . $idProduct . $idAttr) % 38;
};
$offerCount = 0;
foreach ($demo as $prod) {
    $idProduct = (int) $prod['id_product'];
    $productEan = $ean('8002' . str_pad((string) $idProduct, 8, '0', STR_PAD_LEFT));
    $db->update('product', ['ean13' => $productEan], 'id_product = ' . $idProduct);
    $combos = $db->executeS('SELECT id_product_attribute, price FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product = ' . $idProduct . ' ORDER BY id_product_attribute') ?: [];
    $variants = $combos === [] ? [[0, 0.0, $productEan]] : [];
    foreach ($combos as $c) {
        $idAttr = (int) $c['id_product_attribute'];
        $comboEan = $ean('8001' . str_pad((string) $idProduct, 3, '0', STR_PAD_LEFT) . str_pad((string) $idAttr, 5, '0', STR_PAD_LEFT));
        $db->update('product_attribute', ['ean13' => $comboEan], 'id_product_attribute = ' . $idAttr);
        $variants[] = [$idAttr, (float) $c['price'], $comboEan];
    }
    foreach ($variants as [$idAttr, $impact, $variantEan]) {
        $base = (float) $prod['price'] + $impact;
        foreach ($vendorIds as $code => $idVendor) {
            // A offers everything, B two thirds, C every even product: realistic partial overlap
            if (($code === 'vendor-b' && $idProduct % 3 === 0) || ($code === 'vendor-c' && $idProduct % 2 === 1)) {
                continue;
            }
            $price = round($base * $factor($code, $idProduct, $idAttr), 2);
            $discount = ($code === 'vendor-b' && $idAttr === 0 && $idProduct % 2 === 0) ? round($price * 0.85, 2) : null;
            $offers->upsert([
                'id_vendor' => $idVendor, 'id_product' => $idProduct, 'id_product_attribute' => $idAttr,
                'vendor_sku' => strtoupper(substr($code, -1)) . '-' . $idProduct . '-' . $idAttr, 'ean13' => $variantEan,
                'price' => $price, 'discount_price' => $discount,
                'discount_from' => $discount ? date('Y-m-d 00:00:00', strtotime('-1 day')) : null,
                'discount_to' => $discount ? date('Y-m-d 23:59:59', strtotime('+30 days')) : null,
                'quantity' => $stock($code, $idProduct, $idAttr), 'condition' => 'new', 'active' => true,
            ]);
            ++$offerCount;
        }
    }
    $out(sprintf('Demo product #%-3d %-40s EAN %s, %d variant(s)', $idProduct, $prod['name'], $productEan, count($variants)));
}
$out(sprintf('Offers on demo products: %d (total offers in DB: %d)', $offerCount, (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_offer`')));

// ------------------------------------------------------------------ API tokens
$tokens = new ApiTokenRepository();
$tokenOut = [];
foreach (['Demo admin token' => null] + array_combine(array_map(static fn ($c) => 'Demo token ' . $c, array_keys($vendorIds)), array_values($vendorIds)) as $label => $idVendor) {
    $row = $tokens->findByLabel($label);
    $tokenOut[$label] = $row ? $row['token'] : $tokens->issue($label, $idVendor);
}

// ------------------------------------------------------------------ test customer (Milan)
$customer = $ensureCustomer('customer@marketplace.test', 'Customer123!', 'Mario', 'Rossi');
$hasAddress = (int) $db->getValue('SELECT id_address FROM `' . _DB_PREFIX_ . 'address` WHERE deleted = 0 AND id_customer = ' . (int) $customer->id . ' AND postcode = \'20121\'');
if (!$hasAddress) {
    $a = new Address();
    $a->id_customer = (int) $customer->id;
    $a->alias = 'Home Milano';
    $a->firstname = 'Mario';
    $a->lastname = 'Rossi';
    $a->address1 = 'Via Dante 1';
    $a->postcode = '20121';
    $a->city = 'Milano';
    $a->id_country = $idIT;
    $a->id_state = (int) State::getIdByIso('MI', $idIT);
    $a->phone = '+39 02 1234567';
    $a->add();
    $hasAddress = (int) $a->id;
}
$out(sprintf('Test customer customer@marketplace.test / Customer123! id=%d address id=%d (Milano 20121, IT)', $customer->id, $hasAddress));

// ------------------------------------------------------------------ verification: client reference scenario
$result = (new CartOptimizationService())->optimize([new BasketLine($p1, 0, 1), new BasketLine($p2, 0, 1)], new Destination($idIT, '20121', 'IT'));
$out('');
$out('Client scenario (1 x Product 1 + 1 x Product 2, delivery Milano 20121):');
foreach ($result->vendors as $va) {
    foreach ($va->lines as $l) {
        $out(sprintf('  %-9s product %d x%d @ €%.2f', $va->vendorName, $l->idProduct, $l->quantity, $l->unitPrice));
    }
    $out(sprintf('  %-9s subtotal €%.2f + shipping €%.2f (%s)', $va->vendorName, $va->subtotal, $va->shippingCost, $va->shippingTier));
}
$out(sprintf('  TOTAL €%.2f (%s, proven optimal: %s)', $result->total(), $result->mode, $result->provenOptimal ? 'yes' : 'no'));

$out('');
$out('API tokens (Authorization: Bearer <token>):');
foreach ($tokenOut as $label => $t) {
    $out(sprintf('  %-22s %s', $label, $t));
}
$out('');
$out('API example: curl -H "Authorization: Bearer ' . $tokenOut['Demo admin token'] . '" "http://localhost:8080/index.php?fc=module&module=bxmarketplace&controller=api&resource=vendors"');

Logger::channel('seed')->info('Demo data seeded', ['vendors' => $vendorIds, 'p1' => $p1, 'p2' => $p2, 'demo_offers' => $offerCount]);
exit(abs($result->total() - 35.0) < 0.001 ? 0 : 1);

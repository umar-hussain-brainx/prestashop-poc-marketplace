<?php
/**
 * BrainX vendor portal — DEV-ONLY demo history seeder (CLI only).
 *
 *   docker compose exec -T -u www-data prestashop php modules/bxmpvendorportal/tools/seed_history.php [options]
 *
 * Inserts ~N days of realistic vendor sub-orders so the dashboard / analytics have data before real checkout orders
 * exist. To keep the native data consistent it writes ONLY bxmp_vendor_order + bxmp_vendor_order_line rows:
 *   - reference prefix "HIST-" (HIST-<VENDORCODE>-<YYYYMMDD>-<n>) — the marker every consumer can filter on;
 *   - NO PrestaShop order, cart, customer, stock or payment is created or modified;
 *   - id_order is a synthetic id ≥ 4 000 000 000 (no such PS order): the core schema has UNIQUE(id_order, id_vendor),
 *     so a constant id_order = 0 would allow a single history row per vendor;
 *   - lines reference the vendor's real offers (id_offer / product / SKU) with prices around the current offer price;
 *     commission is computed with the core CommissionCalculator and the vendor's terms; shipping with its rules
 *     (destination IT 20121);
 *   - status by age: last 2 days pending/accepted, 3-9 days accepted/shipped/delivered, older delivered (≈5 % cancelled).
 * Idempotent: deterministic per vendor/day (seeded RNG) and existing references are skipped.
 *
 * Options:
 *   --days=90          history length (1..730)
 *   --vendor=vendor-a  only this vendor code (default: all active vendors)
 *   --purge            delete every HIST- row (and its lines) and exit
 *   --reset            purge then seed again
 *   --staff            also create the demo staff user staff-a@marketplace.test / Vendor123! (role staff of vendor-a)
 *                      and manager-a@marketplace.test / Vendor123! (role manager of vendor-a)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$psRoot = dirname(__DIR__, 3);
require_once $psRoot . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

use BrainX\Marketplace\Domain\Commission\CommissionCalculator;
use BrainX\Marketplace\Domain\Commission\CommissionTerms;
use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Shipping\ShippingRuleResolver;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\ShippingRuleRepository;
use PrestaShop\PrestaShop\Adapter\ServiceLocator;

const HIST_PREFIX = 'HIST-';
const HIST_ID_ORDER_BASE = 4000000000;

$opts = getopt('', ['days::', 'vendor::', 'purge', 'reset', 'staff', 'help']);
if (isset($opts['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 2400);
    exit(0);
}
$db = Db::getInstance();
$p = _DB_PREFIX_;

$purge = static function () use ($db, $p): int {
    $ids = array_map('intval', array_column($db->executeS("SELECT id_vendor_order FROM `{$p}bxmp_vendor_order` WHERE reference LIKE 'HIST-%'") ?: [], 'id_vendor_order'));
    foreach (array_chunk($ids, 500) as $chunk) {
        $db->execute("DELETE FROM `{$p}bxmp_vendor_order_line` WHERE id_vendor_order IN (" . implode(',', $chunk) . ')');
        $db->execute("DELETE FROM `{$p}bxmp_vendor_order` WHERE id_vendor_order IN (" . implode(',', $chunk) . ')');
    }

    return count($ids);
};

if (isset($opts['purge']) || isset($opts['reset'])) {
    $n = $purge();
    echo "Purged {$n} HIST- vendor orders.\n";
    Logger::channel('vendor')->info('seed_history purge', ['deleted' => $n]);
    if (isset($opts['purge'])) {
        exit(0);
    }
}

// ------------------------------------------------------------------ optional demo staff / manager users
if (isset($opts['staff'])) {
    $hasher = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
    $idVendorA = BxmpVendor::getIdByCode('vendor-a');
    foreach (['staff-a@marketplace.test' => ['Staff', 'staff'], 'manager-a@marketplace.test' => ['Manager', 'manager']] as $email => [$last, $role]) {
        $c = new Customer();
        $c->getByEmail($email);
        if (!Validate::isLoadedObject($c)) {
            $c = new Customer();
            $c->email = $email;
            $c->firstname = 'Vendor A';
            $c->lastname = $last;
            $c->id_gender = 1;
            $c->is_guest = false;
            $c->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
        }
        $c->passwd = $hasher->hash('Vendor123!');
        $c->active = true;
        $c->save();
        if ($idVendorA) {
            $vu = new BxmpVendorUser(BxmpVendorUser::findId($idVendorA, (int) $c->id) ?: null);
            $vu->id_vendor = $idVendorA;
            $vu->id_customer = (int) $c->id;
            $vu->role = $role;
            $vu->active = true;
            $vu->save();
        }
        echo "Demo user {$email} / Vendor123! → {$role} of vendor-a\n";
    }
}

// ------------------------------------------------------------------ history
$days = max(1, min(730, (int) ($opts['days'] ?? 90)));
$vendorFilter = isset($opts['vendor']) ? (string) $opts['vendor'] : null;
$vendors = array_filter(BxmpVendor::getAll(true), static fn ($v) => $vendorFilter === null || $v['code'] === $vendorFilter);
$calc = new CommissionCalculator();
$resolver = new ShippingRuleResolver();
$policies = (new ShippingRuleRepository())->policiesFor(array_map(static fn ($v) => (int) $v['id_vendor'], $vendors));
$dest = new Destination((int) Country::getByIso('IT'), '20121');
$onShipping = (bool) Configuration::get('BXMP_COMMISSION_ON_SHIPPING');
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$carriers = ['BRT', 'GLS', 'DHL', 'UPS', 'SDA', 'Poste Italiane'];
$today = new DateTimeImmutable('today');
$created = 0;
$skipped = 0;

foreach ($vendors as $v) {
    $idVendor = (int) $v['id_vendor'];
    $offers = $db->executeS("SELECT o.id_offer, o.id_product, o.id_product_attribute, o.vendor_sku, o.price, pl.name
        FROM `{$p}bxmp_offer` o LEFT JOIN `{$p}product_lang` pl ON pl.id_product = o.id_product AND pl.id_lang = {$idLang} AND pl.id_shop = 1
        WHERE o.id_vendor = {$idVendor} AND o.active = 1 ORDER BY o.id_offer") ?: [];
    if ($offers === []) {
        echo "Vendor {$v['code']}: no active offers, skipped.\n";
        continue;
    }
    // popularity weights: a few best sellers (Zipf-like over a vendor-specific shuffle)
    mt_srand(crc32('pop:' . $v['code']));
    shuffle($offers);
    $weights = [];
    foreach ($offers as $i => $o) {
        $weights[$i] = 1 / ($i + 1) ** 0.9;
    }
    $wTotal = array_sum($weights);
    $pick = static function () use ($offers, $weights, $wTotal): array {
        $r = mt_rand() / mt_getrandmax() * $wTotal;
        foreach ($weights as $i => $w) {
            if (($r -= $w) <= 0) {
                return $offers[$i];
            }
        }

        return $offers[0];
    };
    $terms = CommissionTerms::fromVendorRow($v, $onShipping);
    $rules = isset($policies[$idVendor]) ? $policies[$idVendor]->rules : [];
    $scale = ['vendor-a' => 1.0, 'vendor-b' => 0.75, 'vendor-c' => 0.5][$v['code']] ?? 0.6;

    for ($d = $days - 1; $d >= 0; --$d) {
        $day = $today->modify("-{$d} days");
        mt_srand(crc32($v['code'] . ':' . $day->format('Ymd')));
        $dow = (int) $day->format('N');
        $growth = 1 + 0.6 * (($days - $d) / $days);                       // gentle upward trend
        $season = [1 => 0.9, 2 => 0.85, 3 => 0.95, 4 => 1.0, 5 => 1.15, 6 => 1.35, 7 => 1.25][$dow];
        $lambda = 2.2 * $scale * $growth * $season;
        // Poisson draw (Knuth)
        $l = exp(-$lambda);
        $k = 0;
        $prod = mt_rand() / mt_getrandmax();
        while ($prod > $l) {
            ++$k;
            $prod *= mt_rand() / mt_getrandmax();
        }
        for ($n = 1; $n <= $k; ++$n) {
            $ref = sprintf('%s%s-%s-%02d', HIST_PREFIX, strtoupper($v['code']), $day->format('Ymd'), $n);
            $lines = [];
            $nLines = mt_rand(1, 100) <= 70 ? 1 : (mt_rand(1, 100) <= 75 ? 2 : 3);
            for ($i = 0; $i < $nLines; ++$i) {
                $o = $pick();
                $key = (int) $o['id_offer'];
                $unit = round((float) $o['price'] * (0.93 + mt_rand(0, 12) / 100), 2);
                $qty = mt_rand(1, 100) <= 75 ? 1 : mt_rand(2, 4);
                if (isset($lines[$key])) {
                    $lines[$key]['quantity'] += $qty;
                    continue;
                }
                $lines[$key] = ['offer' => $o, 'unit_price' => $unit, 'quantity' => $qty];
            }
            $time = $day->setTime(mt_rand(7, 22), mt_rand(0, 59), mt_rand(0, 59));
            $now = new DateTimeImmutable();
            if ($time > $now) { // today: never in the future
                $time = $now->modify('-' . mt_rand(5, 300) . ' minutes');
                $time = $time < $day ? $day : $time;
            }
            $ageDays = $d;
            $roll = mt_rand(1, 100);
            if ($ageDays <= 2) {
                $status = $roll <= 60 ? 'pending' : 'accepted';
            } elseif ($ageDays <= 9) {
                $status = $roll <= 15 ? 'accepted' : ($roll <= 60 ? 'shipped' : ($roll <= 96 ? 'delivered' : 'cancelled'));
            } else {
                $status = $roll <= 95 ? 'delivered' : 'cancelled';
            }
            $carrier = $carriers[mt_rand(0, count($carriers) - 1)];
            $tracking = strtoupper(substr($carrier, 0, 2)) . $day->format('ymd') . sprintf('%06d', mt_rand(0, 999999));
            if ($db->getValue("SELECT id_vendor_order FROM `{$p}bxmp_vendor_order` WHERE id_vendor = {$idVendor} AND reference = '" . pSQL($ref) . "'")) {
                ++$skipped;
                continue;
            }
            $lineArr = array_values($lines);
            $subtotal = array_sum(array_map(static fn ($l) => $l['unit_price'] * $l['quantity'], $lineArr));
            $shipping = $rules === [] ? 0.0 : ($resolver->costFor($rules, $dest, $subtotal) ?? 0.0);
            $oc = $calc->calculateOrder(array_map(static fn ($l) => ['unit_price' => $l['unit_price'], 'quantity' => $l['quantity']], $lineArr), $shipping, $terms);
            $hasTracking = in_array($status, ['shipped', 'delivered'], true);
            $updated = $time->modify('+' . min($ageDays, mt_rand(1, 4)) . ' days');
            $db->insert('bxmp_vendor_order', [
                'id_order' => HIST_ID_ORDER_BASE + (int) $day->format('ymd') * 100 + $n,
                'id_vendor' => $idVendor,
                'reference' => pSQL($ref),
                'total_products' => $oc->productsTotal,
                'total_shipping' => $oc->shippingTotal,
                'total_paid' => $oc->totalPaid(),
                'commission_amount' => $oc->commission,
                'vendor_payout' => $oc->vendorPayout(),
                'status' => $status,
                'tracking_number' => $hasTracking ? pSQL($tracking) : null,
                'carrier_name' => $hasTracking ? pSQL($carrier) : null,
                'payment_transfer_ref' => $status === 'delivered' && $ageDays > 14 ? pSQL('HIST-TR-' . $day->format('ym') . '-' . $idVendor) : null,
                'date_add' => $time->format('Y-m-d H:i:s'),
                'date_upd' => ($updated > new DateTimeImmutable() ? $time : $updated)->format('Y-m-d H:i:s'),
            ], true);
            $idVo = (int) $db->Insert_ID();
            foreach ($lineArr as $i => $l) {
                $db->insert('bxmp_vendor_order_line', [
                    'id_vendor_order' => $idVo,
                    'id_offer' => (int) $l['offer']['id_offer'],
                    'id_product' => (int) $l['offer']['id_product'],
                    'id_product_attribute' => (int) $l['offer']['id_product_attribute'],
                    'product_name' => pSQL((string) ($l['offer']['name'] ?: 'Product #' . $l['offer']['id_product'])),
                    'vendor_sku' => pSQL((string) $l['offer']['vendor_sku']),
                    'quantity' => (int) $l['quantity'],
                    'unit_price' => (float) $l['unit_price'],
                    'commission_amount' => (float) ($oc->lineCommissions[$i] ?? 0),
                ]);
            }
            ++$created;
        }
    }
    $count = (int) $db->getValue("SELECT COUNT(*) FROM `{$p}bxmp_vendor_order` WHERE id_vendor = {$idVendor} AND reference LIKE 'HIST-%'");
    echo sprintf("Vendor %-10s %4d HIST- orders in total\n", $v['code'], $count);
}
Logger::channel('vendor')->info('seed_history run', ['days' => $days, 'created' => $created, 'skipped' => $skipped]);
echo "Created {$created}, skipped (already present) {$skipped}. Remove with --purge.\n";

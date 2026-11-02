<?php
/**
 * Temporary verification fixtures for bxmpadmin (vendor orders "BXADM-T-*", adjustments and settlements tagged
 * "[bxadm-test]"), used when no real / HIST- vendor orders exist yet.
 *
 *   php modules/bxmpadmin/tools/test_fixtures.php create    # idempotent (cleans first)
 *   php modules/bxmpadmin/tools/test_fixtures.php cleanup
 *
 * Run inside the container as www-data from the PrestaShop root.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = getcwd();
if (!file_exists($root . '/config/config.inc.php')) {
    $root = dirname(__DIR__, 3);
}
require_once $root . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

use BrainX\Marketplace\Domain\Commission\CommissionCalculator;
use BrainX\Marketplace\Domain\Commission\CommissionTerms;

const PREFIX = 'BXADM-T-';
const TAG = '[bxadm-test]';

$db = Db::getInstance();
$p = _DB_PREFIX_;

function cleanup(Db $db, string $p): void
{
    $ids = array_column($db->executeS("SELECT id_vendor_order FROM {$p}bxmp_vendor_order WHERE reference LIKE '" . PREFIX . "%'") ?: [], 'id_vendor_order');
    if ($ids) {
        $db->execute("DELETE FROM {$p}bxmp_vendor_order_line WHERE id_vendor_order IN (" . implode(',', array_map('intval', $ids)) . ')');
        $db->execute("DELETE FROM {$p}bxmp_vendor_order WHERE id_vendor_order IN (" . implode(',', array_map('intval', $ids)) . ')');
    }
    $db->execute("DELETE FROM {$p}bxmp_commission_adjustment WHERE reason LIKE '%" . pSQL(TAG) . "%'");
    // settlements created while the fixtures were loaded
    if ($since = Configuration::get('BXADM_TEST_SINCE')) {
        $db->execute("DELETE FROM {$p}bxmp_settlement WHERE date_add >= '" . pSQL((string) $since) . "'");
        Configuration::deleteByName('BXADM_TEST_SINCE');
    }
    echo 'cleaned ' . count($ids) . " fixture vendor orders\n";
}

$mode = $argv[1] ?? 'create';
cleanup($db, $p);
if ($mode === 'cleanup') {
    exit(0);
}

Configuration::updateValue('BXADM_TEST_SINCE', date('Y-m-d H:i:s'));
$vendors = $db->executeS("SELECT * FROM {$p}bxmp_vendor ORDER BY id_vendor") ?: [];
$offers = [];
foreach ($db->executeS("SELECT o.*, pl.name FROM {$p}bxmp_offer o LEFT JOIN {$p}product_lang pl ON pl.id_product = o.id_product AND pl.id_lang = 1 AND pl.id_shop = 1 WHERE o.active = 1") ?: [] as $o) {
    $offers[(int) $o['id_vendor']][] = $o;
}
$psOrders = array_map('intval', array_column($db->executeS("SELECT id_order FROM {$p}orders ORDER BY id_order") ?: [], 'id_order'));
$calc = new CommissionCalculator();
mt_srand(42);
$statuses = ['delivered', 'delivered', 'delivered', 'shipped', 'shipped', 'accepted', 'pending', 'cancelled'];
$n = 0;
$fakeOrder = 990000;
for ($day = 58; $day >= 0; $day -= 2) {
    $date = date('Y-m-d H:i:s', strtotime("-$day days 10:00") + mt_rand(0, 30000));
    // one parent order, 1-2 vendors
    $idOrder = $day <= 4 && $psOrders ? array_shift($psOrders) : ++$fakeOrder;
    $vs = $vendors;
    shuffle($vs);
    foreach (array_slice($vs, 0, mt_rand(1, 2)) as $v) {
        $vOffers = $offers[(int) $v['id_vendor']] ?? [];
        if (!$vOffers) {
            continue;
        }
        shuffle($vOffers);
        $lines = [];
        foreach (array_slice($vOffers, 0, mt_rand(1, 3)) as $o) {
            $lines[] = ['offer' => $o, 'unit_price' => (float) $o['price'], 'quantity' => mt_rand(1, 3)];
        }
        $shipping = [0.0, 5.0, 6.9, 2.9][mt_rand(0, 3)];
        $terms = CommissionTerms::fromVendorRow($v);
        $oc = $calc->calculateOrder($lines, $shipping, $terms);
        $status = $statuses[mt_rand(0, count($statuses) - 1)];
        $commission = $oc->commission;
        ++$n;
        $ref = PREFIX . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $transfer = $status === 'cancelled' ? null : (mt_rand(0, 9) < 8 ? 'MGP-TR-' . (100000 + $n) : null);
        if ($n === 7) { // anomaly: commission computed with an old rate
            $commission = round($commission * 0.8, 2);
        }
        if ($n === 11) { // anomaly: cancelled but paid out
            $status = 'cancelled';
            $transfer = 'MGP-TR-' . (100000 + $n);
        }
        $upd = in_array($status, ['shipped', 'delivered'], true) ? date('Y-m-d H:i:s', strtotime($date) + mt_rand(6, 60) * 3600) : $date;
        $db->insert('bxmp_vendor_order', [
            'id_order' => $idOrder, 'id_vendor' => (int) $v['id_vendor'], 'reference' => $ref,
            'total_products' => $oc->productsTotal, 'total_shipping' => $oc->shippingTotal, 'total_paid' => $oc->totalPaid(),
            'commission_amount' => $commission, 'vendor_payout' => round($oc->totalPaid() - $commission, 2),
            'status' => $status, 'tracking_number' => in_array($status, ['shipped', 'delivered'], true) ? 'TRK' . $n : null,
            'carrier_name' => 'Demo carrier', 'payment_transfer_ref' => $transfer, 'date_add' => $date, 'date_upd' => $upd,
        ], true);
        $idVo = (int) $db->Insert_ID();
        foreach ($lines as $k => $l) {
            $db->insert('bxmp_vendor_order_line', [
                'id_vendor_order' => $idVo, 'id_offer' => (int) $l['offer']['id_offer'], 'id_product' => (int) $l['offer']['id_product'],
                'id_product_attribute' => (int) $l['offer']['id_product_attribute'], 'product_name' => pSQL((string) $l['offer']['name']),
                'vendor_sku' => pSQL((string) $l['offer']['vendor_sku']), 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                'commission_amount' => $n === 7 ? round($oc->lineCommissions[$k] * 0.8, 2) : $oc->lineCommissions[$k],
            ]);
        }
    }
}
$db->insert('bxmp_commission_adjustment', ['id_vendor' => 2, 'id_vendor_order' => null, 'amount' => -15.0, 'reason' => pSQL('Late shipment penalty ' . TAG), 'date_add' => date('Y-m-d H:i:s', strtotime('-10 days'))], true);
$db->insert('bxmp_commission_adjustment', ['id_vendor' => 1, 'id_vendor_order' => null, 'amount' => 8.5, 'reason' => pSQL('Commission refund on returned item ' . TAG), 'date_add' => date('Y-m-d H:i:s', strtotime('-3 days'))], true);
echo "created $n fixture vendor orders\n";

<?php
/**
 * Integration check (CLI, running shop): vendor order export + order status CSV import through the job queue.
 * Creates two fixture vendor orders (references BXCSV-IT-*), exports, imports status updates, asserts, cleans up.
 *
 *   docker compose exec -T -u www-data prestashop php modules/bxmpcsv/tests/Integration/order_flow_check.php
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require_once dirname(__DIR__, 4) . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

use BrainX\Csv\ImportService;
use BrainX\Csv\OrderExportService;

$db = Db::getInstance();
$p = _DB_PREFIX_;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $fail += $ok ? 0 : 1;
};
$cleanup = static function () use ($db, $p): void {
    $ids = array_column($db->executeS("SELECT id_vendor_order FROM `{$p}bxmp_vendor_order` WHERE reference LIKE 'BXCSV-IT-%'") ?: [], 'id_vendor_order');
    if ($ids) {
        $db->execute("DELETE FROM `{$p}bxmp_vendor_order_line` WHERE id_vendor_order IN (" . implode(',', array_map('intval', $ids)) . ')');
        $db->execute("DELETE FROM `{$p}bxmp_vendor_order` WHERE id_vendor_order IN (" . implode(',', array_map('intval', $ids)) . ')');
    }
};
$cleanup();
$now = date('Y-m-d H:i:s');
$mk = static function (int $idVendor, string $ref, string $status) use ($db, $now): int {
    $db->insert('bxmp_vendor_order', ['id_order' => 970000 + random_int(1, 9999), 'id_vendor' => $idVendor, 'reference' => $ref, 'total_products' => 25, 'total_shipping' => 5,
        'total_paid' => 30, 'commission_amount' => 2.5, 'vendor_payout' => 27.5, 'status' => $status, 'date_add' => $now, 'date_upd' => $now]);
    $id = (int) $db->Insert_ID();
    $db->insert('bxmp_vendor_order_line', ['id_vendor_order' => $id, 'id_offer' => 1, 'id_product' => 20, 'id_product_attribute' => 0, 'product_name' => 'Product 1',
        'vendor_sku' => 'VENDOR-A-P1', 'quantity' => 5, 'unit_price' => 5, 'commission_amount' => 2.5]);

    return $id;
};
try {
    $a = $mk(1, 'BXCSV-IT-1', 'pending');
    $b = $mk(1, 'BXCSV-IT-2', 'accepted');

    $rows = (new OrderExportService())->rows(1, ['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d'), 'status' => ['pending', 'accepted']]);
    $mine = array_values(array_filter($rows, static fn ($r) => str_starts_with((string) $r['vendor_order_reference'], 'BXCSV-IT-')));
    $check(count($mine) === 2 && $mine[0]['vendor_sku'] === 'VENDOR-A-P1' && $mine[0]['line_total_tax_excl'] === '25.00', 'export rows (vendor 1, today, pending+accepted): ' . count($rows) . ' line(s), fixtures present');
    $csv = (new OrderExportService())->exportCsv(1, ['status' => ['accepted']]);
    $check(str_starts_with($csv, "\xEF\xBB\xBFvendor_order_reference;") && str_contains($csv, 'BXCSV-IT-2') && !str_contains($csv, 'BXCSV-IT-1;'), 'CSV export with status filter');
    $check(!str_contains((new OrderExportService())->exportCsv(2), 'BXCSV-IT-'), 'vendor 2 export does not contain vendor 1 orders');

    $file = tempnam(sys_get_temp_dir(), 'bxst');
    file_put_contents($file, "vendor_order_reference,status,tracking_number,carrier_name\nBXCSV-IT-1,accepted,,\nBXCSV-IT-2,Spedito,1Z999AA1,UPS\nBXCSV-IT-2,pending,,\nBXADM-UNKNOWN-REF,shipped,X,Y\n");
    $hookCalls = (int) $db->getValue("SELECT COUNT(*) FROM `{$p}bxmp_log` WHERE message = 'Vendor order status updated'");
    $svc = new ImportService();
    $id = $svc->enqueueUpload(1, $file, 'order_status');
    $job = $svc->process($id, 30);
    $check($job['status'] === 'done' && (int) $job['rows_ok'] === 2 && (int) $job['rows_error'] === 2, sprintf('status job #%d: %s ok=%d errors=%d', $id, $job['status'], $job['rows_ok'], $job['rows_error']));
    $r1 = $db->getRow("SELECT * FROM `{$p}bxmp_vendor_order` WHERE id_vendor_order = {$a}");
    $r2 = $db->getRow("SELECT * FROM `{$p}bxmp_vendor_order` WHERE id_vendor_order = {$b}");
    $check($r1['status'] === 'accepted', 'BXCSV-IT-1 pending → accepted');
    $check($r2['status'] === 'shipped' && $r2['tracking_number'] === '1Z999AA1' && $r2['carrier_name'] === 'UPS', 'BXCSV-IT-2 accepted → shipped with tracking/carrier');
    $check(str_contains((string) $job['log'], 'transition shipped → pending is not allowed') && str_contains((string) $job['log'], 'not found for this vendor'), 'invalid transition + unknown reference reported per row');
    $after = (int) $db->getValue("SELECT COUNT(*) FROM `{$p}bxmp_log` WHERE message = 'Vendor order status updated'");
    $check($after - $hookCalls === 2, 'actionBxmpVendorOrderStatusUpdate dispatched twice (logged)');
    // vendor 2 must not be able to update vendor 1 orders
    file_put_contents($file, "vendor_order_reference;status\nBXCSV-IT-2;delivered\n");
    $job = $svc->process($svc->enqueueUpload(2, $file, 'order_status'), 30);
    $check((int) $job['rows_error'] === 1 && $db->getValue("SELECT status FROM `{$p}bxmp_vendor_order` WHERE id_vendor_order = {$b}") === 'shipped', 'cross-vendor update refused');
    @unlink($file);
} finally {
    $cleanup();
}
echo $fail ? "{$fail} check(s) FAILED\n" : "ALL OK\n";
exit($fail ? 1 : 0);

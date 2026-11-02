<?php
/**
 * Performance sanity run (CLI, running shop): a temporary vendor imports a generated N-row file twice
 * (1st run = master product creation, 2nd run = offer updates), throughput is printed, then everything created is
 * removed again (products, offers, categories, manufacturer, vendor, jobs).
 *
 *   docker compose exec -T -u www-data prestashop php modules/bxmpcsv/tests/Integration/perf_check.php [--rows=4000] [--keep]
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$o = getopt('', ['rows::', 'keep']);
$rows = max(10, (int) ($o['rows'] ?? 4000));
require_once dirname(__DIR__, 4) . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

use BrainX\Csv\Worker\ImportWorker;

$db = Db::getInstance();
$p = _DB_PREFIX_;
$file = sys_get_temp_dir() . '/bxmpcsv-perf.csv';
passthru(PHP_BINARY . ' ' . escapeshellarg(_PS_MODULE_DIR_ . 'bxmpcsv/tools/generate_perf_csv.php') . ' --rows=' . $rows . ' --out=' . escapeshellarg($file) . ' --matched=100');

$v = new BxmpVendor((int) BxmpVendor::getIdByCode('perf-vendor') ?: null);
$v->name = 'Perf Vendor';
$v->code = 'perf-vendor';
$v->email = 'perf@marketplace.test';
$v->active = false; // never visible in the shop
$v->commission_rate = 10;
$v->save();
$idVendor = (int) $v->id;

$run = static function (string $label) use ($file, $idVendor, $db, $p): void {
    $svc = new BrainX\Csv\ImportService();
    $id = $svc->enqueueUpload($idVendor, $file, 'offers', ['full_sync' => false]);
    $t = microtime(true);
    $worker = new ImportWorker(static fn ($l) => print($l . "\n"));
    $worker->run(0, $id, false);
    $s = microtime(true) - $t;
    $job = $svc->getJob($id);
    printf("%s: job #%d %s, %d rows in %.1f s = %.0f rows/s, peak mem %.0f MB — %s\n", $label, $id, $job['status'], $job['rows_total'], $s, $job['rows_total'] / $s,
        memory_get_peak_usage(true) / 1048576, json_encode($job['stats']));
};
try {
    $run('RUN 1 (create)');
    $run('RUN 2 (update)');
    printf("offers of perf vendor: %d, products created: %d\n", (int) $db->getValue("SELECT COUNT(*) FROM `{$p}bxmp_offer` WHERE id_vendor = {$idVendor}"),
        (int) $db->getValue("SELECT COUNT(*) FROM `{$p}product` WHERE reference LIKE 'PERF-MPN-%'"));
} finally {
    if (!isset($o['keep'])) {
        $t = microtime(true);
        $ids = array_column($db->executeS("SELECT id_product FROM `{$p}product` WHERE reference LIKE 'PERF-MPN-%'") ?: [], 'id_product');
        foreach ($ids as $idP) {
            (new Product((int) $idP))->delete();
        }
        $db->delete('bxmp_offer', 'id_vendor = ' . $idVendor);
        $db->delete('bxmp_match_exception', 'id_vendor = ' . $idVendor);
        foreach (array_column($db->executeS("SELECT id_import_job FROM `{$p}bxmp_import_job` WHERE id_vendor = {$idVendor}") ?: [], 'id_import_job') as $j) {
            foreach (['.json', '.csv', '.errors.csv'] as $ext) {
                @unlink(BrainX\Csv\Config::jobsDir() . '/' . $j . $ext);
            }
        }
        $db->delete('bxmp_import_job', 'id_vendor = ' . $idVendor);
        $perfCat = (int) $db->getValue("SELECT c.id_category FROM `{$p}category` c JOIN `{$p}category_lang` cl ON cl.id_category = c.id_category WHERE cl.name = 'Perf' AND c.id_parent = " . (int) Configuration::get('PS_HOME_CATEGORY'));
        if ($perfCat) {
            (new Category($perfCat))->delete();
        }
        $m = (int) $db->getValue("SELECT id_manufacturer FROM `{$p}manufacturer` WHERE name = 'Perf Brand'");
        if ($m) {
            (new Manufacturer($m))->delete();
        }
        (new BxmpVendor($idVendor))->delete();
        @unlink($file);
        printf("cleanup: %d products removed in %.1f s\n", count($ids), microtime(true) - $t);
    }
}

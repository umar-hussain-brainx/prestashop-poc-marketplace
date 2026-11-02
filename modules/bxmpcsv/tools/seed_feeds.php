<?php
/**
 * Demo scheduled feeds (idempotent, CLI): copies samples/feeds/<vendor-code>.csv into var/bxmp_feeds/ (docker host:
 * ./var/feeds) and creates one bxmp_feed per demo vendor (relative path, every 60 minutes).
 *
 *   docker compose exec -T -u www-data prestashop php modules/bxmpcsv/tools/seed_feeds.php [--force] [--minutes=60]
 *
 * --force overwrites feed files that already exist in var/bxmp_feeds.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$opts = getopt('', ['force', 'minutes::']);
require_once dirname(__DIR__, 3) . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

use BrainX\Csv\Config;

$dir = Config::feedsDir();
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}
$minutes = max(1, (int) ($opts['minutes'] ?? 60));
foreach (glob(dirname(__DIR__) . '/samples/feeds/*.csv') as $sample) {
    $code = basename($sample, '.csv');
    $idVendor = (int) BxmpVendor::getIdByCode($code);
    if (!$idVendor) {
        echo "skip {$code}: vendor not found\n";
        continue;
    }
    $target = $dir . '/' . basename($sample);
    if (!is_file($target) || isset($opts['force'])) {
        copy($sample, $target);
        @chmod($target, 0664);
    }
    $rel = basename($sample);
    $idFeed = (int) Db::getInstance()->getValue('SELECT id_feed FROM `' . _DB_PREFIX_ . 'bxmp_feed` WHERE id_vendor = ' . $idVendor . " AND url_or_path = '" . pSQL($rel) . "'");
    $feed = new BxmpFeed($idFeed ?: null);
    $feed->id_vendor = $idVendor;
    $feed->url_or_path = $rel;
    $feed->schedule_minutes = $idFeed ? (int) $feed->schedule_minutes : $minutes;
    $feed->active = true;
    $feed->save();
    printf("feed #%d vendor %s (#%d) → %s every %d min\n", $feed->id, $code, $idVendor, $target, $feed->schedule_minutes);
}

<?php
/**
 * Vendor order export (CLI).
 *
 *   php modules/bxmpcsv/tools/export_orders.php --vendor=ID|0 [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--status=pending,accepted] [--out=file.csv] [--delimiter=;]
 *
 * Without --out the CSV is written to stdout.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$opts = getopt('', ['vendor:', 'from::', 'to::', 'status::', 'out::', 'delimiter::']);
if (!isset($opts['vendor'])) {
    fwrite(STDERR, "Usage: php export_orders.php --vendor=ID|0 [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--status=a,b] [--out=file.csv]\n");
    exit(2);
}
require_once dirname(__DIR__, 3) . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

$filters = [
    'date_from' => $opts['from'] ?? null,
    'date_to' => $opts['to'] ?? null,
    'status' => isset($opts['status']) ? array_filter(explode(',', (string) $opts['status'])) : [],
];
$svc = new BrainX\Csv\OrderExportService();
$delimiter = (string) ($opts['delimiter'] ?? ';');
if (!empty($opts['out'])) {
    $n = $svc->exportToFile((int) $opts['vendor'], (string) $opts['out'], $filters, $delimiter);
    fwrite(STDERR, "{$n} line(s) written to {$opts['out']}\n");
} else {
    echo $svc->exportCsv((int) $opts['vendor'], $filters, $delimiter);
}
exit(0);

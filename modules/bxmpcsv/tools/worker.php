<?php
/**
 * BrainX Marketplace CSV import worker (CLI).
 *
 *   php modules/bxmpcsv/tools/worker.php [--max-time=SECONDS] [--job=ID] [--no-feeds] [--loop[=SLEEP]] [--quiet]
 *
 *   --max-time  stop after this many seconds (finishing the current chunk); default 0 = until the queue is empty
 *   --job       process only this job
 *   --no-feeds  do not enqueue due scheduled feeds first
 *   --loop      daemon mode: keep polling the queue every SLEEP seconds (default 10)
 *
 * Crontab (every minute, as the web server user):
 *   * * * * * www-data php /var/www/html/modules/bxmpcsv/tools/worker.php --max-time=55 --quiet
 * Docker:
 *   docker compose exec -T -u www-data prestashop php modules/bxmpcsv/tools/worker.php
 * Exit code: 0 ok, 1 a processed job failed, 2 bootstrap error, 3 another worker holds the lock.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$opts = getopt('', ['max-time::', 'job::', 'no-feeds', 'loop::', 'quiet', 'help']);
if (isset($opts['help'])) {
    echo preg_replace('/^ \* ?/m', '', substr((string) file_get_contents(__FILE__), 6, 900)), "\n";
    exit(0);
}

$psRoot = dirname(__DIR__, 3);
require_once $psRoot . '/config/config.inc.php';
if (!defined('_PS_VERSION_') || !Module::isInstalled('bxmpcsv')) {
    fwrite(STDERR, "PrestaShop bootstrap failed or module bxmpcsv not installed\n");
    exit(2);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Hint: run as www-data (docker compose exec -u www-data ...) so generated files keep the right owner.\n");
}
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';

$context = Context::getContext();
if (!$context->employee) {
    $context->employee = new Employee((int) Db::getInstance()->getValue('SELECT id_employee FROM `' . _DB_PREFIX_ . 'employee` WHERE active = 1 ORDER BY id_employee'));
}

$quiet = isset($opts['quiet']);
$out = $quiet ? null : static function (string $line): void {
    echo $line, "\n";
};
$worker = new BrainX\Csv\Worker\ImportWorker($out);
$maxTime = (int) ($opts['max-time'] ?? 0);
$job = isset($opts['job']) ? (int) $opts['job'] : null;
$feeds = !isset($opts['no-feeds']);
$loop = array_key_exists('loop', $opts);
$sleep = max(1, (int) (($opts['loop'] ?? false) ?: 10));

$exit = 0;
do {
    $summary = $worker->run($maxTime, $job, $feeds);
    if ($summary['busy']) {
        $exit = 3;
    }
    foreach ($summary['jobs'] as $j) {
        if ($j['status'] === 'failed') {
            $exit = 1;
        }
    }
    if (!$quiet && !$loop) {
        echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    }
    if ($loop) {
        sleep($sleep);
    }
} while ($loop);

exit($exit);

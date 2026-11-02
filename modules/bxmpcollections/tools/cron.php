<?php
/**
 * CLI cron: materialise collection schedules (log activation / deactivation transitions, clear caches).
 *   php modules/bxmpcollections/tools/cron.php [--dry-run]
 * Suggested crontab: every 5 minutes. HTTP alternative: the module front controller "cron" (?token=BXMPCOL_CRON_TOKEN).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = file_exists(getcwd() . '/config/config.inc.php') ? getcwd() : dirname(__DIR__, 3);
require_once $root . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

$report = (new BrainX\Collections\Service\ScheduleRunner())->run(null, in_array('--dry-run', $argv, true));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

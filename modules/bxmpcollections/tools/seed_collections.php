<?php
/**
 * Seed / refresh the demo thematic collections (idempotent). Run from the PrestaShop root as www-data:
 *   php modules/bxmpcollections/tools/seed_collections.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = file_exists(getcwd() . '/config/config.inc.php') ? getcwd() : dirname(__DIR__, 3);
require_once $root . '/config/config.inc.php';
require_once __DIR__ . '/DemoCollections.php';

foreach (BxmpDemoCollections::seed() as $slug => $r) {
    $status = BrainX\Collections\Domain\ScheduleResolver::status(true, $r['start'], $r['end']);
    printf("%-16s #%-3d %-9s %s → %s  products=%d categories=%d\n", $slug, $r['id'], $status, $r['start'] ?? '-', $r['end'] ?? '-', $r['products'], $r['categories']);
}
$report = (new BrainX\Collections\Service\ScheduleRunner())->run();
printf("schedule materialised: %d change(s)\n", count($report['changes']));

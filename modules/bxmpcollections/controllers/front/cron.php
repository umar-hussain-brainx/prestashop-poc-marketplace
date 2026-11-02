<?php
/**
 * HTTP cron: index.php?fc=module&module=bxmpcollections&controller=cron&token=<BXMPCOL_CRON_TOKEN>[&dry_run=1]
 * Materialises collection schedules (logs activation changes, clears caches) and answers JSON.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use BrainX\Collections\Service\ScheduleRunner;

class BxmpcollectionsCronModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $token = (string) Tools::getValue('token');
        if ($token === '' || !hash_equals((string) Configuration::get('BXMPCOL_CRON_TOKEN'), $token)) {
            http_response_code(403);
            $this->ajaxRender(json_encode(['error' => 'invalid token']));

            return;
        }
        $report = (new ScheduleRunner())->run(null, (bool) Tools::getValue('dry_run'));
        $this->ajaxRender(json_encode(['ok' => true] + $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

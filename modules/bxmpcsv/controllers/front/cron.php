<?php
/**
 * Token-protected cron / webcron endpoint:
 *   GET index.php?fc=module&module=bxmpcsv&controller=cron&token=<BXMPCSV_CRON_TOKEN>[&max_time=50][&job=ID][&feeds=0]
 * Enqueues due feeds and runs the import worker for at most max_time seconds; answers JSON.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use BrainX\Csv\Config;
use BrainX\Csv\Worker\ImportWorker;

class BxmpcsvCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    public $ssl = false;
    /** @var bool */
    public $ajax = true;

    public function initContent()
    {
        $token = (string) Tools::getValue('token');
        $expected = Config::cronToken();
        if ($expected === '' || !hash_equals($expected, $token)) {
            $this->json(['error' => 'invalid token'], 403);
        }
        ignore_user_abort(true);
        $max = (int) Tools::getValue('max_time', Config::int('BXMPCSV_MAX_RUNTIME') ?: 50);
        $max = max(5, min(600, $max));
        $job = (int) Tools::getValue('job') ?: null;
        $feeds = Tools::getValue('feeds', '1') !== '0';
        try {
            $summary = (new ImportWorker())->run($max, $job, $feeds);
            $this->json(['ok' => true] + $summary);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('bxmpcsv cron: ' . $e->getMessage(), 3);
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

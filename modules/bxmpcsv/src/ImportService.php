<?php

declare(strict_types=1);

namespace BrainX\Csv;

use BrainX\Csv\Parser\CsvException;
use BrainX\Csv\Parser\CsvReader;
use BrainX\Csv\Schema\OfferSchema;
use BrainX\Csv\Schema\OrderStatusSchema;
use BrainX\Csv\Schema\Schema;
use BrainX\Csv\Worker\ImportWorker;
use BrainX\Csv\Worker\JobMeta;
use BrainX\Marketplace\Infrastructure\Logger;

/**
 * Public entry point of the CSV import pipeline (used by the back office, the vendor portal and the feed scheduler).
 *
 *   $svc = new \BrainX\Csv\ImportService();
 *   $idJob = $svc->enqueueUpload($idVendor, $_FILES['csv']['tmp_name'], 'offers', ['original_name' => $_FILES['csv']['name']]);
 *   $svc->process($idJob, 20);           // optional: process right away (else the cron / CLI worker picks it up)
 *   $job = $svc->getJob($idJob);         // status, counters, stats, log
 *
 * Types: "offers" (unified catalog / offer CSV, docs/CSV_SCHEMA.md) and "order_status"
 * (vendor_order_reference, status, tracking_number, carrier_name).
 * Options: original_name, full_sync (bool, offers missing from the file → quantity 0), auto_create (bool),
 * delimiter (force ";", ",", "\t" or "|"), id_feed.
 * The header is validated synchronously: an ImportException is thrown for unusable files, row problems are
 * reported per row by the worker.
 */
final class ImportService
{
    public const TYPES = ['offers', 'order_status'];
    public const MAX_BYTES = 104857600; // 100 MB

    public static function schemaFor(string $type): Schema
    {
        return $type === 'order_status' ? new OrderStatusSchema() : new OfferSchema();
    }

    /** Enqueue a local file (HTTP upload tmp file or any readable file; it is copied into the private storage). */
    public function enqueueUpload(int $idVendor, string $tmpFile, string $type = 'offers', array $options = []): int
    {
        $this->checkVendorAndType($idVendor, $type);
        if (!is_file($tmpFile) || !is_readable($tmpFile)) {
            throw new ImportException('Uploaded file is missing or unreadable.');
        }
        $name = (string) ($options['original_name'] ?? basename($tmpFile));
        if (!preg_match('/\.(csv|txt|tsv)$/i', $name) && isset($options['original_name'])) {
            throw new ImportException('Only .csv / .txt / .tsv files are accepted.');
        }
        $this->checkFile($tmpFile, $type, $options['delimiter'] ?? null);
        $id = $this->createJob($idVendor, $type, 'upload', null, $options + ['original_name' => $name]);
        $dest = Config::jobsDir() . '/' . $id . '.csv';
        $ok = is_uploaded_file($tmpFile) ? move_uploaded_file($tmpFile, $dest) : copy($tmpFile, $dest);
        if (!$ok) {
            $this->failJob($id, 'Could not store the uploaded file');
            throw new ImportException('Could not store the uploaded file.');
        }
        \Db::getInstance()->update('bxmp_import_job', ['file_path' => pSQL($dest)], 'id_import_job = ' . $id);
        $meta = JobMeta::load($id);
        $meta->data['local_file'] = $dest;
        $meta->save();

        return $id;
    }

    /** Enqueue a remote http(s) file: it is downloaded by the worker when the job starts. */
    public function enqueueUrl(int $idVendor, string $url, string $type = 'offers', array $options = []): int
    {
        $this->checkVendorAndType($idVendor, $type);
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false || mb_strlen($url) > 512) {
            throw new ImportException('Invalid URL (http/https only, max 512 chars).');
        }

        return $this->createJob($idVendor, $type, 'url', $url, $options + ['original_name' => basename((string) parse_url($url, PHP_URL_PATH)) ?: 'feed.csv']);
    }

    /** Enqueue a server file (absolute, or relative to var/bxmp_feeds; must be inside an allowed directory). */
    public function enqueuePath(int $idVendor, string $path, string $type = 'offers', array $options = []): int
    {
        $this->checkVendorAndType($idVendor, $type);
        $real = self::resolvePath($path);
        $this->checkFile($real, $type, $options['delimiter'] ?? null);

        return $this->createJob($idVendor, $type, 'path', $real, $options + ['original_name' => basename($real)]);
    }

    /** Run the worker for this job only (synchronous, time-boxed). Returns the job after processing. */
    public function process(int $idJob, int $maxSeconds = 30): ?array
    {
        (new ImportWorker())->run($maxSeconds, $idJob, false);

        return $this->getJob($idJob);
    }

    /** Job row + vendor name + detailed stats (updated / created / combinations / exceptions / skipped / invalid...). */
    public function getJob(int $idJob): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT j.*, v.name AS vendor_name FROM `' . _DB_PREFIX_ . 'bxmp_import_job` j
            LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = j.id_vendor WHERE j.id_import_job = ' . $idJob);
        if (!$row) {
            return null;
        }
        $meta = JobMeta::load($idJob);
        $row['stats'] = $meta->stats();
        $row['processed'] = (int) $meta->data['processed'];
        $row['original_name'] = $meta->data['original_name'];
        $row['options'] = $meta->data['options'];
        $row['seconds'] = round((float) $meta->data['seconds'], 2);
        $row['has_error_report'] = is_file(JobMeta::errorsPath($idJob));

        return $row;
    }

    /** @return array<int, array<string,mixed>> latest jobs (optionally of one vendor), without the log column */
    public function listJobs(?int $idVendor = null, int $limit = 50, int $offset = 0): array
    {
        return \Db::getInstance()->executeS('SELECT j.id_import_job, j.id_vendor, j.type, j.source, j.file_path, j.status, j.rows_total, j.rows_ok, j.rows_error,
                j.date_add, j.date_start, j.date_end, v.name AS vendor_name
            FROM `' . _DB_PREFIX_ . 'bxmp_import_job` j LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = j.id_vendor'
            . ($idVendor !== null ? ' WHERE j.id_vendor = ' . $idVendor : '')
            . ' ORDER BY j.id_import_job DESC LIMIT ' . max(0, $offset) . ', ' . max(1, min(500, $limit))) ?: [];
    }

    /** Path of the per-row error / warning report CSV (line, vendor_sku, outcome, messages) or null. */
    public function errorReport(int $idJob): ?string
    {
        $p = JobMeta::errorsPath($idJob);

        return is_file($p) ? $p : null;
    }

    /** Downloadable empty template (header + 2 example rows) for a type. */
    public static function templateCsv(string $type = 'offers'): string
    {
        $file = _PS_MODULE_DIR_ . 'bxmpcsv/samples/' . ($type === 'order_status' ? 'order-status-template.csv' : 'offers-template.csv');

        return is_file($file) ? (string) file_get_contents($file) : "\xEF\xBB\xBF" . implode(';', array_keys(self::schemaFor($type)->columns())) . "\n";
    }

    /** Resolve and authorise a server path (relative paths are relative to var/bxmp_feeds). */
    public static function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new ImportException('Empty path.');
        }
        $candidate = str_starts_with($path, '/') ? $path : Config::feedsDir() . '/' . $path;
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            throw new ImportException('File not found: ' . $path);
        }
        foreach (Config::allowedRoots() as $root) {
            if (str_starts_with($real, rtrim($root, '/') . '/')) {
                return $real;
            }
        }
        throw new ImportException('Path not allowed (allowed: ' . implode(', ', Config::allowedRoots()) . ').');
    }

    private function checkVendorAndType(int $idVendor, string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ImportException('Unknown import type "' . $type . '".');
        }
        if ($idVendor === 0 && $type === 'order_status') {
            return; // admin scope
        }
        if ($idVendor <= 0 || !(int) \Db::getInstance()->getValue('SELECT id_vendor FROM `' . _DB_PREFIX_ . 'bxmp_vendor` WHERE id_vendor = ' . $idVendor)) {
            throw new ImportException('Unknown vendor #' . $idVendor . '.');
        }
    }

    private function checkFile(string $file, string $type, ?string $delimiter): void
    {
        $size = (int) @filesize($file);
        if ($size <= 0) {
            throw new ImportException('The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new ImportException('The file is larger than ' . (self::MAX_BYTES >> 20) . ' MB.');
        }
        try {
            $reader = new CsvReader($file, $delimiter ?: null);
            self::schemaFor($type)->mapHeader($reader->header());
        } catch (CsvException $e) {
            throw new ImportException($e->getMessage());
        }
    }

    private function createJob(int $idVendor, string $type, string $source, ?string $filePath, array $options): int
    {
        $now = date('Y-m-d H:i:s');
        \Db::getInstance()->insert('bxmp_import_job', [
            'id_vendor' => $idVendor,
            'type' => pSQL($type),
            'source' => pSQL($source),
            'file_path' => $filePath === null ? null : pSQL($filePath),
            'status' => 'queued',
            'rows_total' => 0, 'rows_ok' => 0, 'rows_error' => 0,
            'log' => pSQL('[' . $now . '] queued (' . $source . ($options['original_name'] ? ': ' . $options['original_name'] : '') . ")\n", true),
            'date_add' => $now,
        ], true);
        $id = (int) \Db::getInstance()->Insert_ID();
        $opts = [];
        foreach (['full_sync', 'auto_create'] as $k) {
            if (array_key_exists($k, $options) && $options[$k] !== null) {
                $opts[$k] = (bool) $options[$k];
            }
        }
        if (!empty($options['delimiter'])) {
            $opts['delimiter'] = (string) $options['delimiter'];
        }
        if (!empty($options['id_feed'])) {
            $opts['id_feed'] = (int) $options['id_feed'];
        }
        JobMeta::create($id, ['options' => $opts, 'original_name' => $options['original_name'] ?? null]);
        Logger::channel('csv')->info('Import job queued', ['id_import_job' => $id, 'id_vendor' => $idVendor, 'type' => $type, 'source' => $source]);

        return $id;
    }

    private function failJob(int $id, string $message): void
    {
        \Db::getInstance()->update('bxmp_import_job', ['status' => 'failed', 'date_end' => date('Y-m-d H:i:s'), 'log' => pSQL($message, true)], 'id_import_job = ' . $id);
    }
}

<?php

declare(strict_types=1);

namespace BrainX\Csv\Worker;

use BrainX\Csv\Config;
use BrainX\Csv\FeedScheduler;
use BrainX\Csv\ImportService;
use BrainX\Csv\OrderStatusService;
use BrainX\Csv\Parser\CsvException;
use BrainX\Csv\Parser\CsvReader;
use BrainX\Csv\Parser\CsvWriter;
use BrainX\Csv\Schema\Schema;
use BrainX\Csv\Validation\OfferRowValidator;
use BrainX\Csv\Validation\OrderStatusRowValidator;
use BrainX\Marketplace\Infrastructure\Logger;

/**
 * Asynchronous import worker. One worker at a time (MySQL named lock); jobs are processed in chunks of
 * BXMPCSV_CHUNK_SIZE rows, each chunk in one DB transaction followed by a progress checkpoint (resume offset in the
 * job side-car file), so a time-boxed run (cron / webcron) simply continues the job on the next invocation.
 *
 * Entry points: tools/worker.php (CLI), controllers/front/cron.php (token), back office "Run worker now",
 * ImportService::process().
 */
final class ImportWorker
{
    private const LOCK = 'bxmpcsv_worker';
    private const LOG_CAP = 60000;
    private const LOG_ROW_MESSAGES = 300;

    /** @var \Closure|null */
    private $out;

    public function __construct(?callable $output = null)
    {
        $this->out = $output !== null ? \Closure::fromCallable($output) : null;
    }

    /**
     * @param int $maxSeconds 0 = until the queue is empty
     * @param int|null $onlyJob process this job only
     *
     * @return array{busy:bool, feeds_enqueued:array, jobs:array, seconds:float}
     */
    public function run(int $maxSeconds = 0, ?int $onlyJob = null, bool $enqueueFeeds = true): array
    {
        $t0 = microtime(true);
        $deadline = $maxSeconds > 0 ? $t0 + $maxSeconds : PHP_FLOAT_MAX;
        $summary = ['busy' => false, 'feeds_enqueued' => [], 'jobs' => [], 'seconds' => 0.0];
        if ($enqueueFeeds) {
            $summary['feeds_enqueued'] = (new FeedScheduler())->enqueueDue();
            foreach ($summary['feeds_enqueued'] as $idFeed => $idJob) {
                $this->say("feed #{$idFeed} → job #{$idJob} queued");
            }
        }
        $db = \Db::getInstance();
        if (!(int) $db->getValue("SELECT GET_LOCK('" . self::LOCK . "', " . ($onlyJob ? 10 : 0) . ')')) {
            $this->say('another worker is running, exiting');
            $summary['busy'] = true;

            return $summary;
        }
        try {
            @set_time_limit($maxSeconds > 0 ? $maxSeconds + 60 : 0);
            while (microtime(true) < $deadline) {
                $job = $this->nextJob($onlyJob);
                if (!$job) {
                    break;
                }
                $summary['jobs'][] = $this->processJob($job, $deadline);
                if ($onlyJob) {
                    break;
                }
            }
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
        $summary['seconds'] = round(microtime(true) - $t0, 3);

        return $summary;
    }

    private function nextJob(?int $onlyJob): ?array
    {
        $p = _DB_PREFIX_;
        $where = $onlyJob ? 'id_import_job = ' . $onlyJob . " AND status IN ('queued','running')" : "status IN ('queued','running')";

        // resume running jobs first (we hold the lock, so a "running" job is an interrupted one)
        return \Db::getInstance()->getRow("SELECT * FROM `{$p}bxmp_import_job` WHERE {$where} ORDER BY status = 'running' DESC, id_import_job ASC") ?: null;
    }

    /** @return array{id_import_job:int, status:string, processed:int, stats:array} */
    public function processJob(array $job, float $deadline): array
    {
        $id = (int) $job['id_import_job'];
        $meta = JobMeta::load($id);
        $log = new JobLog($id, self::LOG_CAP);
        try {
            if ($job['status'] === 'queued') {
                $this->start($job, $meta, $log);
                $job = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_import_job` WHERE id_import_job = ' . $id);
            }
            $finished = $this->runChunks($job, $meta, $log, $deadline);
            if ($finished) {
                $this->finish($job, $meta, $log);
            } else {
                $log->add(sprintf('paused after %d/%d rows (time budget), will resume on next run', $meta->data['processed'], (int) $job['rows_total']));
                $log->flush();
            }
        } catch (\Throwable $e) {
            $this->fail($id, $meta, $log, $e);
        }
        $status = (string) \Db::getInstance()->getValue('SELECT status FROM `' . _DB_PREFIX_ . 'bxmp_import_job` WHERE id_import_job = ' . $id);
        $this->say(sprintf('job #%d %s: %d rows processed %s', $id, $status, $meta->data['processed'], json_encode($meta->stats())));

        return ['id_import_job' => $id, 'status' => $status, 'processed' => (int) $meta->data['processed'], 'stats' => $meta->stats()];
    }

    private function start(array $job, JobMeta $meta, JobLog $log): void
    {
        $id = (int) $job['id_import_job'];
        $local = $meta->data['local_file'] ?? null;
        if ($job['source'] === 'url') {
            $local = Config::jobsDir() . '/' . $id . '.csv';
            $this->download((string) $job['file_path'], $local);
            $log->add('downloaded ' . $job['file_path'] . ' (' . filesize($local) . ' bytes)');
        } elseif ($job['source'] === 'path') {
            $src = ImportService::resolvePath((string) $job['file_path']);
            $local = Config::jobsDir() . '/' . $id . '.csv';
            if (!copy($src, $local)) {
                throw new CsvException('Cannot snapshot ' . $src);
            }
            $log->add('snapshot of ' . $src . ' (' . filesize($local) . ' bytes)');
        }
        if (!$local || !is_file($local)) {
            throw new CsvException('Import file is missing.');
        }
        $reader = new CsvReader($local, $meta->option('delimiter'));
        $schema = ImportService::schemaFor((string) $job['type']);
        $mapped = $schema->mapHeader($reader->header());
        $map = $mapped['map'];
        $total = 0;
        $duplicates = [];
        $skuIndex = array_search('vendor_sku', $map, true);
        $seen = [];
        foreach ($reader->records() as $line => $rec) {
            ++$total;
            if ($skuIndex !== false) {
                $sku = trim((string) ($rec[$skuIndex] ?? ''));
                if ($sku !== '') {
                    if (isset($seen[$sku])) {
                        $duplicates[$line] = $seen[$sku];
                    } else {
                        $seen[$sku] = $line;
                    }
                }
            }
        }
        $meta->data['local_file'] = $local;
        $meta->data['delimiter'] = $reader->delimiter();
        $meta->data['decimal_hint'] = $reader->decimalHint();
        $meta->data['header_map'] = $map;
        $meta->data['duplicates'] = $duplicates;
        $meta->data['processed'] = 0;
        $meta->save();
        @unlink(JobMeta::errorsPath($id));

        $now = date('Y-m-d H:i:s');
        \Db::getInstance()->update('bxmp_import_job', ['status' => 'running', 'date_start' => $now, 'rows_total' => $total, 'rows_ok' => 0, 'rows_error' => 0], 'id_import_job = ' . $id);
        $delimName = ["\t" => 'TAB', ';' => ';', ',' => ',', '|' => '|'][$reader->delimiter()] ?? $reader->delimiter();
        $log->add(sprintf('started: %d data rows, delimiter "%s", decimal "%s"%s, columns: %s', $total, $delimName, $reader->decimalHint(), $reader->hadBom() ? ', UTF-8 BOM' : '', implode(', ', $map)));
        if ($mapped['unknown'] !== []) {
            $log->add('ignored unknown column(s): ' . implode(', ', $mapped['unknown']));
        }
        if ($mapped['duplicates'] !== []) {
            $log->add('ignored duplicate column(s): ' . implode(', ', $mapped['duplicates']));
        }
        if ($duplicates !== []) {
            $log->add(count($duplicates) . ' row(s) repeat an earlier vendor_sku and will be rejected');
        }
        $opts = $meta->data['options'];
        $log->add('options: full_sync=' . (int) ($opts['full_sync'] ?? Config::bool('BXMPCSV_FULL_SYNC')) . ', auto_create=' . (int) ($opts['auto_create'] ?? Config::bool('BXMPCSV_AUTO_CREATE')) . ', chunk=' . $this->chunkSize());
        $log->flush();
    }

    private function chunkSize(): int
    {
        return max(10, min(5000, Config::int('BXMPCSV_CHUNK_SIZE') ?: 500));
    }

    /** @return bool true when all rows have been processed */
    private function runChunks(array $job, JobMeta $meta, JobLog $log, float $deadline): bool
    {
        $id = (int) $job['id_import_job'];
        $type = (string) $job['type'];
        $idVendor = (int) $job['id_vendor'];
        $reader = new CsvReader((string) $meta->data['local_file'], (string) $meta->data['delimiter']);
        $map = array_map('strval', (array) $meta->data['header_map']);
        $map = array_combine(array_map('intval', array_keys($map)), array_values($map));
        $duplicates = (array) $meta->data['duplicates'];
        $chunk = $this->chunkSize();
        $fullSync = (bool) $meta->option('full_sync', Config::bool('BXMPCSV_FULL_SYNC'));

        $offerValidator = new OfferRowValidator((string) $meta->data['decimal_hint']);
        $statusValidator = new OrderStatusRowValidator();
        $processor = $type === 'offers' ? new OfferRowProcessor($idVendor, $id, $meta->data['options']) : null;
        $statusService = $type === 'order_status' ? new OrderStatusService() : null;
        $errFile = fopen(JobMeta::errorsPath($id), 'ab');
        if ($errFile && ftell($errFile) === 0) {
            CsvWriter::write($errFile, ['line', 'vendor_sku_or_reference', 'outcome', 'messages'], [], ';');
        }

        $db = \Db::getInstance();
        $ok = (int) $job['rows_ok'];
        $err = (int) $job['rows_error'];
        $inChunk = 0;
        $t = microtime(true);
        $db->execute('START TRANSACTION');
        $finished = true;
        try {
            foreach ($reader->records((int) $meta->data['processed']) as $line => $rec) {
                $raw = Schema::combine($map, $rec);
                $key = (string) ($raw['vendor_sku'] ?? $raw['vendor_order_reference'] ?? '');
                $messages = [];
                if ($type === 'offers') {
                    $res = $offerValidator->validate($raw, $line);
                    $errors = $res->errors;
                    if (isset($duplicates[$line]) || isset($duplicates[(string) $line])) {
                        $errors[] = 'vendor_sku: duplicate of row ' . ($duplicates[$line] ?? $duplicates[(string) $line]);
                    }
                    if ($errors !== []) {
                        $meta->inc('invalid');
                        ++$err;
                        $outcome = 'invalid';
                        $messages = $errors;
                        $fullSync && $key !== '' && $this->protectSku($idVendor, $key);
                    } else {
                        try {
                            $o = $processor->process($res->data, $line);
                            $meta->inc($o->status);
                            $outcome = $o->status;
                            $o->isOk() ? ++$ok : null;
                            if (!$o->isOk()) {
                                $messages[] = $o->message;
                                $fullSync && $this->protectSku($idVendor, $key);
                            }
                            $messages = array_merge($messages, $o->warnings);
                        } catch (\Throwable $e) {
                            $meta->inc('failed');
                            ++$err;
                            $outcome = 'failed';
                            $messages[] = $e->getMessage();
                            $fullSync && $this->protectSku($idVendor, $key);
                        }
                    }
                    if ($res->warnings !== []) {
                        $meta->inc('warnings', count($res->warnings));
                        $messages = array_merge($messages, array_map(static fn ($w) => 'warning: ' . $w, $res->warnings));
                    }
                } else {
                    $res = $statusValidator->validate($raw, $line);
                    if (!$res->isValid()) {
                        $meta->inc('invalid');
                        ++$err;
                        $outcome = 'invalid';
                        $messages = $res->errors;
                    } else {
                        try {
                            $r = $statusService->apply($idVendor, (string) $res->data['vendor_order_reference'], $res->data['status'], $res->data['tracking_number'], $res->data['carrier_name'], 'csv');
                            $outcome = $r['changed'] ? RowOutcome::UPDATED : RowOutcome::SKIPPED;
                            $meta->inc($outcome);
                            ++$ok;
                            if ($r['changed']) {
                                $messages[] = $r['old_status'] . ' → ' . $r['new_status'];
                            }
                        } catch (\Throwable $e) {
                            $meta->inc('failed');
                            ++$err;
                            $outcome = 'failed';
                            $messages[] = $e->getMessage();
                        }
                    }
                    $messages = array_merge($messages, array_map(static fn ($w) => 'warning: ' . $w, $res->warnings));
                }
                if ($messages !== []) {
                    if ($errFile) {
                        fputcsv($errFile, [$line, $key, $outcome, implode(' | ', $messages)], ';', '"', '');
                    }
                    // the DB log keeps the rows that were not applied; warnings of applied rows are in the report CSV
                    if (!in_array($outcome, ['updated', 'created', 'combinations'], true)) {
                        if ($log->rowMessages < self::LOG_ROW_MESSAGES) {
                            $log->add(sprintf('row %d [%s] %s: %s', $line, $key, $outcome, implode(' | ', $messages)));
                        } elseif ($log->rowMessages === self::LOG_ROW_MESSAGES) {
                            $log->add('... further row messages only in the error report CSV');
                        }
                        ++$log->rowMessages;
                    }
                }
                ++$meta->data['processed'];
                ++$inChunk;
                if ($inChunk >= $chunk) {
                    $this->checkpoint($db, $id, $meta, $log, $processor, $ok, $err, $t);
                    $this->say(sprintf('job #%d: %d/%d rows', $id, $meta->data['processed'], (int) $job['rows_total']));
                    $inChunk = 0;
                    $t = microtime(true);
                    if (microtime(true) >= $deadline) {
                        $finished = false;
                        break;
                    }
                    $db->execute('START TRANSACTION');
                }
            }
            if ($inChunk > 0 || $finished) {
                $this->checkpoint($db, $id, $meta, $log, $processor, $ok, $err, $t);
            }
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        } finally {
            if ($errFile) {
                fclose($errFile);
            }
        }

        return $finished;
    }

    private function checkpoint(\Db $db, int $id, JobMeta $meta, JobLog $log, ?OfferRowProcessor $processor, int $ok, int $err, float $t): void
    {
        $processor?->stock()->flush();
        $db->execute('COMMIT');
        ++$meta->data['chunks'];
        $meta->data['seconds'] = (float) $meta->data['seconds'] + (microtime(true) - $t);
        $meta->save();
        $db->update('bxmp_import_job', ['rows_ok' => $ok, 'rows_error' => $err], 'id_import_job = ' . $id);
        $log->flush();
    }

    /** Keep the vendor's existing offer for a SKU out of the full-sync zeroing (row present but not applicable). */
    private function protectSku(int $idVendor, string $sku): void
    {
        if ($sku !== '') {
            \Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . "bxmp_offer` SET date_upd = '" . date('Y-m-d H:i:s') . "' WHERE id_vendor = " . $idVendor . " AND vendor_sku = '" . pSQL($sku) . "'");
        }
    }

    private function finish(array $job, JobMeta $meta, JobLog $log): void
    {
        $id = (int) $job['id_import_job'];
        $idVendor = (int) $job['id_vendor'];
        $fullSync = (bool) $meta->option('full_sync', Config::bool('BXMPCSV_FULL_SYNC'));
        $job = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_import_job` WHERE id_import_job = ' . $id);
        if ($job['type'] === 'offers' && $fullSync) {
            if ((int) $job['rows_ok'] === 0) {
                $log->add('full sync skipped: no valid row in the file (safety)');
            } else {
                $zeroed = $this->zeroMissingOffers($idVendor, (string) $job['date_start']);
                $meta->inc('zeroed', $zeroed);
                $log->add('full sync: ' . $zeroed . ' offer(s) missing from the file set to quantity 0');
            }
        }
        $stats = $meta->stats();
        $summary = sprintf('done: %d rows, %d ok, %d errors — %s; %.1f s (%.0f rows/s)', (int) $job['rows_total'], (int) $job['rows_ok'], (int) $job['rows_error'],
            implode(', ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($stats), $stats)),
            (float) $meta->data['seconds'], (float) $meta->data['seconds'] > 0 ? (int) $meta->data['processed'] / (float) $meta->data['seconds'] : 0);
        $log->add($summary);
        $log->flush(true);
        $meta->save();
        \Db::getInstance()->update('bxmp_import_job', ['status' => 'done', 'date_end' => date('Y-m-d H:i:s')], 'id_import_job = ' . $id);
        Logger::channel('csv')->info('Import job done', ['id_import_job' => $id, 'id_vendor' => $idVendor, 'type' => $job['type'], 'stats' => $stats]);
    }

    private function zeroMissingOffers(int $idVendor, string $since): int
    {
        $p = _DB_PREFIX_;
        $rows = \Db::getInstance()->executeS("SELECT id_offer, id_product, id_product_attribute FROM `{$p}bxmp_offer`
            WHERE id_vendor = {$idVendor} AND quantity > 0 AND date_upd < '" . pSQL($since) . "'") ?: [];
        if ($rows === []) {
            return 0;
        }
        $ids = array_map('intval', array_column($rows, 'id_offer'));
        \Db::getInstance()->execute("UPDATE `{$p}bxmp_offer` SET quantity = 0, date_upd = '" . date('Y-m-d H:i:s') . "' WHERE id_offer IN (" . implode(',', $ids) . ')');
        $stock = new \BrainX\Csv\Service\StockSync();
        foreach ($rows as $r) {
            $stock->touch((int) $r['id_product'], (int) $r['id_product_attribute']);
            \Hook::exec('actionBxmpOfferUpdated', ['id_offer' => (int) $r['id_offer'], 'data' => ['quantity' => 0], 'source' => 'csv_full_sync']);
        }
        $stock->flush();

        return count($rows);
    }

    private function fail(int $id, JobMeta $meta, JobLog $log, \Throwable $e): void
    {
        $meta->save();
        $log->add('FAILED: ' . $e->getMessage());
        $log->flush(true);
        \Db::getInstance()->update('bxmp_import_job', ['status' => 'failed', 'date_end' => date('Y-m-d H:i:s')], 'id_import_job = ' . $id);
        Logger::channel('csv')->error('Import job failed', ['id_import_job' => $id, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    }

    private function download(string $url, string $dest): void
    {
        $fh = fopen($dest, 'wb');
        if (!$fh) {
            throw new CsvException('Cannot write ' . $dest);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FAILONERROR => true, CURLOPT_USERAGENT => 'BrainX-Marketplace-Feed/1.0',
                CURLOPT_MAXFILESIZE => ImportService::MAX_BYTES,
            ]);
            $ok = curl_exec($ch);
            $error = curl_error($ch);
            fclose($fh);
            if ($ok === false) {
                @unlink($dest);
                throw new CsvException('Download failed: ' . $error);
            }
        } else {
            fclose($fh);
            $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1, 'max_redirects' => 3]]));
            if ($data === false) {
                @unlink($dest);
                throw new CsvException('Download failed: ' . $url);
            }
            file_put_contents($dest, $data);
        }
        if ((int) filesize($dest) === 0) {
            throw new CsvException('Downloaded file is empty.');
        }
    }

    private function say(string $msg): void
    {
        if ($this->out) {
            ($this->out)('[' . date('H:i:s') . '] ' . $msg);
        }
    }
}

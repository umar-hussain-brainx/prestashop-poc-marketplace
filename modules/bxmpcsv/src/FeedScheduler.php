<?php

declare(strict_types=1);

namespace BrainX\Csv;

use BrainX\Marketplace\Infrastructure\Logger;

/**
 * Scheduled synchronisation: every active bxmp_feed whose last_run + schedule_minutes is due gets an import job
 * (source url when url_or_path is http(s), else path — relative paths are resolved in var/bxmp_feeds).
 * Feeds are full catalog feeds: the job uses the BXMPCSV_FULL_SYNC setting (offers missing from the feed → qty 0).
 * A feed with a queued / running job is not enqueued again.
 */
final class FeedScheduler
{
    /** @return array<int, array<string,mixed>> */
    public function dueFeeds(?int $now = null): array
    {
        $now ??= time();
        $p = _DB_PREFIX_;

        return \Db::getInstance()->executeS("SELECT f.* FROM `{$p}bxmp_feed` f
            INNER JOIN `{$p}bxmp_vendor` v ON v.id_vendor = f.id_vendor AND v.active = 1
            WHERE f.active = 1 AND (f.last_run IS NULL OR DATE_ADD(f.last_run, INTERVAL GREATEST(f.schedule_minutes, 1) MINUTE) <= '" . date('Y-m-d H:i:s', $now) . "')
            ORDER BY f.last_run IS NULL DESC, f.last_run ASC") ?: [];
    }

    /** @return array<int,int> id_feed => id_import_job */
    public function enqueueDue(): array
    {
        $out = [];
        foreach ($this->dueFeeds() as $feed) {
            try {
                $id = $this->enqueue((int) $feed['id_feed']);
                if ($id) {
                    $out[(int) $feed['id_feed']] = $id;
                }
            } catch (\Throwable $e) {
                Logger::channel('csv')->warning('Feed could not be enqueued', ['id_feed' => (int) $feed['id_feed'], 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * Enqueue one feed now (also used by the back office "Run now" action). Returns 0 when a job of this feed is
     * already pending. last_run is updated even when the source is unavailable (no retry storm).
     */
    public function enqueue(int $idFeed): int
    {
        $p = _DB_PREFIX_;
        $feed = \Db::getInstance()->getRow("SELECT * FROM `{$p}bxmp_feed` WHERE id_feed = " . $idFeed);
        if (!$feed) {
            throw new ImportException('Feed #' . $idFeed . ' not found');
        }
        \Db::getInstance()->update('bxmp_feed', ['last_run' => date('Y-m-d H:i:s')], 'id_feed = ' . $idFeed);
        $src = trim((string) $feed['url_or_path']);
        $isUrl = (bool) preg_match('#^https?://#i', $src);
        $filePath = $isUrl ? $src : ImportService::resolvePath($src);
        $pending = (int) \Db::getInstance()->getValue("SELECT id_import_job FROM `{$p}bxmp_import_job`
            WHERE id_vendor = " . (int) $feed['id_vendor'] . " AND type = 'offers' AND status IN ('queued','running') AND file_path = '" . pSQL($filePath) . "'");
        if ($pending) {
            return 0;
        }
        $svc = new ImportService();
        $options = ['id_feed' => $idFeed, 'full_sync' => Config::bool('BXMPCSV_FULL_SYNC')];
        $id = $isUrl ? $svc->enqueueUrl((int) $feed['id_vendor'], $src, 'offers', $options) : $svc->enqueuePath((int) $feed['id_vendor'], $src, 'offers', $options);
        Logger::channel('csv')->info('Feed enqueued', ['id_feed' => $idFeed, 'id_import_job' => $id]);

        return $id;
    }

    /** Next due date of a feed row (for display). */
    public static function nextRun(array $feed): ?string
    {
        if (!(int) $feed['active']) {
            return null;
        }
        if (empty($feed['last_run'])) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', strtotime((string) $feed['last_run']) + 60 * max(1, (int) $feed['schedule_minutes']));
    }
}

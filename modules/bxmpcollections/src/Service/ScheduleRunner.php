<?php

declare(strict_types=1);

namespace BrainX\Collections\Service;

use BrainX\Collections\Domain\ScheduleResolver;
use BrainX\Collections\Repository\CollectionRepository;
use BrainX\Marketplace\Infrastructure\Logger;

/**
 * Cron runner: materialises the schedule of every collection into bxmp_collection_state, logs each activation /
 * deactivation transition (bxmp_log channel "collections"), fires the hook actionBxmpCollectionStatusChange and clears
 * the module's template cache when something changed. The front office never depends on it (status is always
 * evaluated at render time with ScheduleResolver); the runner gives an audit trail and cache invalidation.
 */
final class ScheduleRunner
{
    public function __construct(private readonly CollectionRepository $repo = new CollectionRepository())
    {
    }

    /** @return array{checked:int, changes: array<int, array<string, mixed>>, next_change: ?string} */
    public function run(?\DateTimeInterface $now = null, bool $dryRun = false): array
    {
        $now ??= new \DateTimeImmutable();
        $db = \Db::getInstance();
        $previous = [];
        foreach ($db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_collection_state`') ?: [] as $r) {
            $previous[(int) $r['id_collection']] = (string) $r['status'];
        }
        $changes = [];
        $next = null;
        $rows = $this->repo->all($now);
        foreach ($rows as $c) {
            $id = (int) $c['id_collection'];
            $status = $c['status'];
            $nc = ScheduleResolver::nextChange((bool) (int) $c['active'], $c['date_start'], $c['date_end'], $now);
            if ($nc !== null && ($next === null || $nc < $next)) {
                $next = $nc;
            }
            $old = $previous[$id] ?? null;
            if ($old === $status) {
                continue;
            }
            $change = ['id_collection' => $id, 'slug' => $c['slug'], 'name' => $c['name'], 'from' => $old, 'to' => $status,
                'event' => $status === ScheduleResolver::LIVE ? 'activated' : ($old === ScheduleResolver::LIVE ? 'deactivated' : 'status'), ];
            $changes[] = $change;
            if ($dryRun) {
                continue;
            }
            $db->execute('REPLACE INTO `' . _DB_PREFIX_ . 'bxmp_collection_state` (id_collection, status, date_upd) VALUES ('
                . $id . ", '" . pSQL($status) . "', '" . pSQL($now->format('Y-m-d H:i:s')) . "')");
            Logger::channel('collections')->info(sprintf('Collection "%s" %s (%s → %s)', $c['name'], $change['event'], $old ?? 'new', $status), $change);
            \Hook::exec('actionBxmpCollectionStatusChange', $change);
        }
        // forget deleted collections
        $ids = array_map(static fn ($r) => (int) $r['id_collection'], $rows);
        if (!$dryRun) {
            $db->delete('bxmp_collection_state', $ids ? 'id_collection NOT IN (' . implode(',', $ids) . ')' : '1');
        }
        if ($changes && !$dryRun) {
            self::clearCaches();
        }

        return ['checked' => count($rows), 'changes' => $changes, 'next_change' => $next];
    }

    public static function clearCaches(): void
    {
        $module = \Module::getInstanceByName('bxmpcollections');
        if ($module && method_exists($module, 'clearCollectionsCache')) {
            $module->clearCollectionsCache();
        }
    }
}

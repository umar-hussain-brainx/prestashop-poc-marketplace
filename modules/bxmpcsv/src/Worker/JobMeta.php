<?php

declare(strict_types=1);

namespace BrainX\Csv\Worker;

use BrainX\Csv\Config;

/**
 * Side-car metadata of an import job (var/bxmp_csv/jobs/<id>.json): options, local snapshot, resume offset,
 * header map and detailed counters. bxmp_import_job keeps the summary (status, rows_total/ok/error, log).
 */
final class JobMeta
{
    public const STAT_KEYS = ['updated', 'created', 'combinations', 'exceptions', 'skipped', 'invalid', 'failed', 'warnings', 'zeroed'];

    /** @var array<string, mixed> */
    public array $data;

    private function __construct(private readonly int $idJob, array $data)
    {
        $this->data = $data + [
            'options' => [],
            'original_name' => null,
            'local_file' => null,
            'processed' => 0,
            'delimiter' => null,
            'decimal_hint' => null,
            'header_map' => null,
            'duplicates' => [],
            'stats' => array_fill_keys(self::STAT_KEYS, 0),
            'chunks' => 0,
            'seconds' => 0.0,
        ];
        $this->data['stats'] += array_fill_keys(self::STAT_KEYS, 0);
    }

    public static function path(int $idJob): string
    {
        return Config::jobsDir() . '/' . $idJob . '.json';
    }

    public static function errorsPath(int $idJob): string
    {
        return Config::jobsDir() . '/' . $idJob . '.errors.csv';
    }

    public static function load(int $idJob): self
    {
        $file = self::path($idJob);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return new self($idJob, is_array($data) ? $data : []);
    }

    public static function create(int $idJob, array $data): self
    {
        $m = new self($idJob, $data);
        $m->save();

        return $m;
    }

    public function save(): void
    {
        file_put_contents(self::path($this->idJob), json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->data['options'][$key] ?? $default;
    }

    public function inc(string $stat, int $by = 1): void
    {
        $this->data['stats'][$stat] = (int) ($this->data['stats'][$stat] ?? 0) + $by;
    }

    public function stats(): array
    {
        return $this->data['stats'];
    }
}

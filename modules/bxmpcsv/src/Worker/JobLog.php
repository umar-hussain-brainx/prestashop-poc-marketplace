<?php

declare(strict_types=1);

namespace BrainX\Csv\Worker;

/** Buffered, size-capped append-only job log (bxmp_import_job.log). */
final class JobLog
{
    /** @var string[] */
    private array $buffer = [];
    public int $rowMessages = 0;

    public function __construct(private readonly int $idJob, private readonly int $cap = 60000)
    {
    }

    public function add(string $line): void
    {
        $this->buffer[] = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $line);
    }

    /** @param bool $force append even when the cap is reached (final summary / failure) */
    public function flush(bool $force = false): void
    {
        if ($this->buffer === []) {
            return;
        }
        $text = implode("\n", $this->buffer) . "\n";
        $this->buffer = [];
        $p = _DB_PREFIX_;
        $len = (int) \Db::getInstance()->getValue("SELECT CHAR_LENGTH(COALESCE(log, '')) FROM `{$p}bxmp_import_job` WHERE id_import_job = " . $this->idJob);
        if (!$force && $len >= $this->cap) {
            return;
        }
        if (!$force && $len + mb_strlen($text) > $this->cap) {
            $text = mb_substr($text, 0, max(0, $this->cap - $len)) . "\n[log truncated — see the error report CSV]\n";
        }
        \Db::getInstance()->execute("UPDATE `{$p}bxmp_import_job` SET log = CONCAT(COALESCE(log, ''), '" . pSQL($text, true) . "') WHERE id_import_job = " . $this->idJob);
    }
}

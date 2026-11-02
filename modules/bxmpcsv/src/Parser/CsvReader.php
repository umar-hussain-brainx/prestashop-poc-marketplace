<?php

declare(strict_types=1);

namespace BrainX\Csv\Parser;

/**
 * Streaming CSV reader for vendor files.
 *
 *  - UTF-8 BOM is stripped; UTF-16 files are rejected with a clear message; non UTF-8 rows (Excel "ANSI"
 *    exports) are converted from Windows-1252.
 *  - Delimiter auto-detection among ";", ",", TAB and "|" (header line, confirmed on the next lines), unless forced.
 *  - Quoted fields with embedded delimiters / new lines / doubled quotes ("") are supported (RFC 4180).
 *  - Records are numbered like spreadsheet rows: the header is row 1, the first data record row 2.
 *    Completely empty records are skipped (but keep their number).
 */
final class CsvReader
{
    public const DELIMITERS = [';', ',', "\t", '|'];

    /** @var resource */
    private $handle;
    private string $delimiter;
    /** @var string[] */
    private array $header = [];
    private int $dataOffset = 0;
    private bool $hadBom = false;

    /**
     * @throws CsvException
     */
    public function __construct(private readonly string $path, ?string $delimiter = null)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CsvException('File not found or not readable: ' . basename($path));
        }
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new CsvException('Cannot open file: ' . basename($path));
        }
        $this->handle = $h;
        $start = (string) fread($h, 4);
        if (str_starts_with($start, "\xFF\xFE") || str_starts_with($start, "\xFE\xFF")) {
            throw new CsvException('UTF-16 encoded files are not supported: save the file as "CSV UTF-8".');
        }
        if (str_starts_with($start, "\xEF\xBB\xBF")) {
            $this->hadBom = true;
            $this->dataOffset = 3;
        }
        fseek($h, $this->dataOffset);
        $this->delimiter = $delimiter ?? self::detectDelimiter($this->sampleLines(6));
        fseek($h, $this->dataOffset);
        $header = $this->readRecord();
        if ($header === null || $this->isEmptyRecord($header)) {
            throw new CsvException('The file is empty (no header line).');
        }
        $this->header = array_map(static fn ($c) => trim((string) $c), $header);
        $this->dataOffset = (int) ftell($h);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /** Decimal separator expected for this file: European ';' files use ",", comma separated files use ".". */
    public function decimalHint(): string
    {
        return $this->delimiter === ',' ? '.' : ',';
    }

    public function hadBom(): bool
    {
        return $this->hadBom;
    }

    /** @return string[] raw header cells */
    public function header(): array
    {
        return $this->header;
    }

    /**
     * Iterate data records: rowNumber => list of cells (strings, UTF-8).
     *
     * @param int $skip number of non-empty data records to skip (resume support)
     *
     * @return \Generator<int, string[]>
     */
    public function records(int $skip = 0): \Generator
    {
        fseek($this->handle, $this->dataOffset);
        $row = 1;
        $seen = 0;
        while (($rec = $this->readRecord()) !== null) {
            ++$row;
            if ($this->isEmptyRecord($rec)) {
                continue;
            }
            ++$seen;
            if ($seen <= $skip) {
                continue;
            }
            yield $row => $rec;
        }
    }

    /** Number of non-empty data records (full scan). */
    public function count(): int
    {
        $n = 0;
        foreach ($this->records() as $_) {
            ++$n;
        }

        return $n;
    }

    /** @param string[] $lines */
    public static function detectDelimiter(array $lines): string
    {
        $lines = array_values(array_filter($lines, static fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return ';';
        }
        $best = ';';
        $bestScore = -1.0;
        foreach (self::DELIMITERS as $d) {
            $counts = array_map(static fn ($l) => self::countOutsideQuotes($l, $d), $lines);
            $head = $counts[0];
            if ($head === 0) {
                continue;
            }
            // consistency: following lines with the same number of delimiters as the header
            $consistent = 1;
            foreach (array_slice($counts, 1) as $c) {
                if ($c === $head) {
                    ++$consistent;
                }
            }
            $score = $head * ($consistent / count($counts));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    private static function countOutsideQuotes(string $line, string $d): int
    {
        $n = 0;
        $inQuotes = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; ++$i) {
            $c = $line[$i];
            if ($c === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $c === $d) {
                ++$n;
            }
        }

        return $n;
    }

    /** @return string[] */
    private function sampleLines(int $n): array
    {
        $lines = [];
        while (count($lines) < $n && ($l = fgets($this->handle, 1 << 20)) !== false) {
            $lines[] = $l;
        }

        return $lines;
    }

    /** @return string[]|null */
    private function readRecord(): ?array
    {
        $rec = fgetcsv($this->handle, 0, $this->delimiter, '"', '');
        if ($rec === false) {
            return null;
        }
        if ($rec === [null]) {
            return [''];
        }

        return array_map(static function ($v): string {
            $v = (string) $v;
            if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
                $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
            }

            return $v;
        }, $rec);
    }

    /** @param array<int, string|null> $rec */
    private function isEmptyRecord(array $rec): bool
    {
        foreach ($rec as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }
}

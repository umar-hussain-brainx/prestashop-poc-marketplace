<?php

declare(strict_types=1);

namespace BrainX\Csv\Parser;

/** Small CSV writer (UTF-8, optional BOM for Excel, RFC 4180 quoting) used by exports and error reports. */
final class CsvWriter
{
    /** @param iterable<array<int|string, scalar|null>> $rows */
    public static function toString(array $header, iterable $rows, string $delimiter = ';', bool $bom = true): string
    {
        $h = fopen('php://temp', 'w+b');
        if ($bom) {
            fwrite($h, "\xEF\xBB\xBF");
        }
        self::write($h, $header, $rows, $delimiter);
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);

        return $out;
    }

    /**
     * @param resource $handle
     * @param iterable<array<int|string, scalar|null>> $rows
     */
    public static function write($handle, array $header, iterable $rows, string $delimiter = ';'): void
    {
        fputcsv($handle, $header, $delimiter, '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach ($header as $i => $col) {
                $v = array_key_exists($col, $row) ? $row[$col] : ($row[$i] ?? '');
                $line[] = self::cell($v);
            }
            fputcsv($handle, $line, $delimiter, '"', '');
        }
    }

    private static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $s = (string) $v;
        // neutralise spreadsheet formula injection
        if ($s !== '' && in_array($s[0], ['=', '+', '@'], true)) {
            $s = "'" . $s;
        }

        return $s;
    }
}

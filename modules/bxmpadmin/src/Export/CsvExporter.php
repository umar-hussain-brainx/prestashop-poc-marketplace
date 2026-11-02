<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Export;

/**
 * CSV writer for every bxmpadmin report (UTF-8 with BOM so Excel opens it correctly, `;` separator by default
 * — the EU Excel default — overridable).
 */
final class CsvExporter
{
    /**
     * @param array<string, string> $columns key in row => header label
     * @param iterable<array<string, mixed>> $rows
     */
    public static function toString(array $columns, iterable $rows, string $separator = ';'): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_values($columns), $separator, '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $k) {
                $v = $row[$k] ?? '';
                if (is_bool($v)) {
                    $v = $v ? '1' : '0';
                } elseif (is_float($v)) {
                    $v = number_format($v, 2, '.', '');
                } elseif (is_string($v) && preg_match('/^-?\d+\.\d{3,}$/', $v)) {
                    $v = number_format((float) $v, 2, '.', '');
                } elseif (is_array($v)) {
                    $v = json_encode($v);
                }
                $line[] = (string) $v;
            }
            fputcsv($fh, $line, $separator, '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return (string) $csv;
    }

    /** Send as a download and stop. */
    public static function send(string $filename, array $columns, iterable $rows): never
    {
        $csv = self::toString($columns, $rows);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }
}

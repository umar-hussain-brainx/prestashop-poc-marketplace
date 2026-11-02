<?php

declare(strict_types=1);

namespace BrainX\Csv\Schema;

use BrainX\Csv\Parser\CsvException;

/**
 * Column definition of an import file type: canonical names, aliases and required columns.
 * Header cells are normalised (lowercase, BOM/quotes stripped, spaces / dashes / dots → "_") before alias lookup.
 */
abstract class Schema
{
    /** @return array<string, string> canonical column => short description */
    abstract public function columns(): array;

    /** @return array<string, string> alias => canonical */
    abstract public function aliases(): array;

    /** @return string[] columns that must be present in the header */
    abstract public function requiredColumns(): array;

    public static function normalizeHeader(string $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
        $h = mb_strtolower(trim($h, " \t\n\r\0\x0B\"'"));
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h) : false;
        $h = $ascii === false ? $h : strtolower($ascii);
        $h = preg_replace('/\(.*?\)/', '', $h) ?? $h;          // "price (tax excl)" → "price"
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;

        return trim($h, '_');
    }

    /**
     * Map header cells to canonical column names.
     *
     * @param string[] $header
     *
     * @return array{map: array<int, string>, unknown: string[], duplicates: string[]} index => canonical
     *
     * @throws CsvException when required columns are missing
     */
    public function mapHeader(array $header): array
    {
        $columns = $this->columns();
        $aliases = $this->aliases();
        $map = [];
        $unknown = [];
        $duplicates = [];
        foreach ($header as $i => $cell) {
            $n = self::normalizeHeader((string) $cell);
            if ($n === '') {
                continue;
            }
            $canonical = isset($columns[$n]) ? $n : ($aliases[$n] ?? null);
            if ($canonical === null) {
                $unknown[] = (string) $cell;
                continue;
            }
            if (in_array($canonical, $map, true)) {
                $duplicates[] = (string) $cell;
                continue;
            }
            $map[$i] = $canonical;
        }
        $missing = array_diff($this->requiredColumns(), $map);
        if ($missing !== []) {
            throw new CsvException('Missing required column(s): ' . implode(', ', $missing) . '. Found: ' . implode(', ', array_map('strval', $header)));
        }

        return ['map' => $map, 'unknown' => $unknown, 'duplicates' => $duplicates];
    }

    /**
     * Combine a record with the header map → canonical associative array (missing cells = '').
     *
     * @param array<int, string> $map
     * @param string[] $record
     *
     * @return array<string, string>
     */
    public static function combine(array $map, array $record): array
    {
        $out = [];
        foreach ($map as $i => $col) {
            $out[$col] = trim((string) ($record[$i] ?? ''));
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace BrainX\Csv\Parser;

/**
 * Locale-tolerant decimal parser for vendor CSV values.
 *
 *   "1234.5", "1234,5", "1.234,56", "1,234.56", "1 234,56", "1'234.56", "€ 12,50", "12.50 EUR"
 *
 * Rules:
 *  - currency symbols / codes and spaces (incl. NBSP) are ignored; a leading "-" or trailing "-" means negative;
 *  - when both "." and "," occur, the right-most one is the decimal separator and the other one must form
 *    valid 3-digit thousand groups;
 *  - a separator that occurs several times is a thousands separator (groups validated);
 *  - a single separator followed by exactly 3 digits is ambiguous ("1.000"): it is resolved with the
 *    $decimalHint (the decimal separator expected for the file, derived from the CSV delimiter: ';' → ',',
 *    ',' → '.'); any other single separator is the decimal separator.
 */
final class NumberParser
{
    /** @return float|null null when the value is not a number */
    public static function parse(string $raw, ?string $decimalHint = null): ?float
    {
        $s = self::normalize($raw, $decimalHint);

        return $s === null ? null : (float) $s;
    }

    /** Integer parse: accepts "12", "12.0", "1.000" (thousands), rejects "12.5". */
    public static function parseInt(string $raw, ?string $decimalHint = null): ?int
    {
        $s = self::normalize($raw, $decimalHint);
        if ($s === null) {
            return null;
        }
        if (str_contains($s, '.')) {
            [$int, $frac] = explode('.', $s, 2);
            if (trim($frac, '0') !== '') {
                return null;
            }
            $s = $int;
        }
        if (strlen(ltrim($s, '-')) > 10) {
            return null;
        }

        return (int) $s;
    }

    /** Canonical numeric string ("-1234.56") or null. */
    public static function normalize(string $raw, ?string $decimalHint = null): ?string
    {
        $s = trim(str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $raw));
        if ($s === '') {
            return null;
        }
        // strip currency symbols / ISO codes
        $s = preg_replace('/(?:€|\$|£|EUR|USD|GBP|CHF)/iu', '', $s) ?? '';
        $s = trim($s);
        $negative = false;
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = ltrim(substr($s, 1));
        } elseif (str_ends_with($s, '-')) {
            $negative = true;
            $s = rtrim(substr($s, 0, -1));
        } elseif (str_starts_with($s, '+')) {
            $s = ltrim(substr($s, 1));
        }
        // spaces and apostrophes are always thousands separators
        if (preg_match('/^\d{1,3}(?:[ \'’]\d{3})+(?:[.,]\d+)?$/u', $s)) {
            $s = str_replace([' ', '\'', '’'], '', $s);
        }
        if (!preg_match('/^[\d.,]+$/', $s) || !preg_match('/\d/', $s)) {
            return null;
        }

        $dots = substr_count($s, '.');
        $commas = substr_count($s, ',');
        if ($dots > 0 && $commas > 0) {
            $decimal = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';
            if (substr_count($s, $decimal) !== 1) {
                return null;
            }
            [$intPart, $frac] = explode($decimal, $s);
            if (!self::validGroups($intPart, $thousands)) {
                return null;
            }
            $int = str_replace($thousands, '', $intPart);
        } elseif ($dots + $commas === 0) {
            $int = $s;
            $frac = '';
        } else {
            $sep = $dots > 0 ? '.' : ',';
            $count = max($dots, $commas);
            if ($count > 1) {
                if (!self::validGroups($s, $sep)) {
                    return null;
                }
                $int = str_replace($sep, '', $s);
                $frac = '';
            } else {
                [$a, $b] = explode($sep, $s);
                $ambiguous = strlen($b) === 3 && $a !== '' && strlen($a) <= 3 && $a !== '0';
                $isThousands = $ambiguous && $decimalHint !== null && $decimalHint !== $sep;
                if ($isThousands) {
                    $int = $a . $b;
                    $frac = '';
                } else {
                    $int = $a === '' ? '0' : $a;
                    $frac = $b;
                }
            }
        }
        if ($int === '' || !ctype_digit($int) || ($frac !== '' && !ctype_digit($frac))) {
            return null;
        }
        $int = ltrim($int, '0');
        $out = ($int === '' ? '0' : $int) . ($frac !== '' ? '.' . $frac : '');

        return ($negative && trim($out, '0.') !== '' ? '-' : '') . $out;
    }

    private static function validGroups(string $s, string $sep): bool
    {
        $groups = explode($sep, $s);
        $first = array_shift($groups);
        if ($first === '' || strlen($first) > 3) {
            return false;
        }
        foreach ($groups as $g) {
            if (strlen($g) !== 3) {
                return false;
            }
        }

        return true;
    }
}

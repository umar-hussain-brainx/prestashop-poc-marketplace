<?php

declare(strict_types=1);

namespace BrainX\Csv\Validation;

/** Strict date parser for CSV values → "Y-m-d H:i:s" or null. */
final class DateParser
{
    private const FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
        'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y',
        'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
    ];

    /** @param bool $endOfDay date-only values become 23:59:59 (promotion end) */
    public static function parse(string $raw, bool $endOfDay = false): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // ISO 8601 with timezone (e.g. 2026-11-30T10:00:00+01:00 / Z)
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})$/', $raw)) {
            $d = date_create($raw);

            return $d ? $d->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s') : null;
        }
        foreach (self::FORMATS as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $raw);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }
            if ($d->format($f) !== $raw) {
                continue; // reject overflow like 2026-02-31
            }
            $year = (int) $d->format('Y');
            if ($year < 2000 || $year > 2100) {
                return null;
            }
            $dateOnly = !str_contains($f, 'H');
            if ($dateOnly && $endOfDay) {
                $d = $d->setTime(23, 59, 59);
            }

            return $d->format('Y-m-d H:i:s');
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace BrainX\Collections\Domain;

/**
 * Single source of truth for the visibility of a thematic collection:
 *
 *   live ⇔ active flag AND (date_start is null OR now ≥ date_start) AND (date_end is null OR now ≤ date_end)
 *
 * Statuses: `live`, `scheduled` (active, starts in the future), `expired` (active, ended), `disabled` (flag off).
 * Null / empty / "0000-00-00 00:00:00" bounds are open. Bounds are inclusive. Pure PHP (unit-tested).
 */
final class ScheduleResolver
{
    public const LIVE = 'live';
    public const SCHEDULED = 'scheduled';
    public const EXPIRED = 'expired';
    public const DISABLED = 'disabled';

    public static function status(bool $active, ?string $dateStart, ?string $dateEnd, ?\DateTimeInterface $now = null): string
    {
        if (!$active) {
            return self::DISABLED;
        }
        $now = self::fmt($now ?? new \DateTimeImmutable());
        $start = self::normalize($dateStart);
        $end = self::normalize($dateEnd, true);
        if ($start !== null && $now < $start) {
            return self::SCHEDULED;
        }
        if ($end !== null && $now > $end) {
            return self::EXPIRED;
        }

        return self::LIVE;
    }

    public static function isLive(bool $active, ?string $dateStart, ?string $dateEnd, ?\DateTimeInterface $now = null): bool
    {
        return self::status($active, $dateStart, $dateEnd, $now) === self::LIVE;
    }

    /** Status of a bxmp_collection row (array with active, date_start, date_end). */
    public static function statusOf(array $row, ?\DateTimeInterface $now = null): string
    {
        return self::status((bool) (int) ($row['active'] ?? 0), $row['date_start'] ?? null, $row['date_end'] ?? null, $now);
    }

    /** Next moment the status changes (start or end bound in the future), null when it never changes again. */
    public static function nextChange(bool $active, ?string $dateStart, ?string $dateEnd, ?\DateTimeInterface $now = null): ?string
    {
        if (!$active) {
            return null;
        }
        $now = self::fmt($now ?? new \DateTimeImmutable());
        foreach ([self::normalize($dateStart), self::normalize($dateEnd, true)] as $bound) {
            if ($bound !== null && $bound > $now) {
                return $bound;
            }
        }

        return null;
    }

    /** Validation message for a window, null when valid. */
    public static function validateWindow(?string $dateStart, ?string $dateEnd): ?string
    {
        foreach (['start' => $dateStart, 'end' => $dateEnd] as $k => $v) {
            if ($v !== null && trim($v) !== '' && self::normalize($v) === null && !str_starts_with($v, '0000-00-00')) {
                return sprintf('Invalid %s date "%s"', $k, $v);
            }
        }
        $s = self::normalize($dateStart);
        $e = self::normalize($dateEnd, true);
        if ($s !== null && $e !== null && $e < $s) {
            return 'The end date must be after the start date';
        }

        return null;
    }

    /** "Y-m-d H:i:s" or null for open bounds; a bare date "Y-m-d" means start of day (end bounds: end of day). */
    public static function normalize(?string $v, bool $endOfDay = false): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || str_starts_with($v, '0000-00-00')) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v) ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $v)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $v) ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $v);

        return $d ? $d->format('Y-m-d H:i:s') : null;
    }

    private static function fmt(\DateTimeInterface $d): string
    {
        return $d->format('Y-m-d H:i:s');
    }
}

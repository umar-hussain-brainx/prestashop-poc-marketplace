<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Domain;

/**
 * Analytics period (inclusive dates) + bucketing for trend charts.
 * Bucket keys match the SQL expressions returned by {@see sqlBucketFormat()}:
 * day = Y-m-d (%Y-%m-%d), week = ISO o-\WW (%x-W%v), month = Y-m (%Y-%m).
 */
final class Period
{
    public const PRESETS = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365];
    public const GRANULARITIES = ['day', 'week', 'month'];

    private function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly string $preset,
        public readonly string $granularity,
    ) {
    }

    /**
     * @param string      $preset      7d|30d|90d|365d|custom (unknown → 30d)
     * @param string|null $granularity day|week|month|null (null → auto by length)
     */
    public static function fromRequest(string $preset, ?string $from = null, ?string $to = null, ?string $granularity = null, ?\DateTimeImmutable $today = null): self
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        if ($preset === 'custom') {
            $f = self::parseDate($from);
            $t = self::parseDate($to);
            if ($f === null || $t === null) {
                $preset = '30d';
            } else {
                if ($f > $t) {
                    [$f, $t] = [$t, $f];
                }
                // cap at 3 years to keep queries / charts bounded
                $min = $t->modify('-1095 days');
                if ($f < $min) {
                    $f = $min;
                }

                return new self($f, $t, 'custom', self::granularity($granularity, $f, $t));
            }
        }
        if (!isset(self::PRESETS[$preset])) {
            $preset = '30d';
        }
        $f = $today->modify('-' . (self::PRESETS[$preset] - 1) . ' days');

        return new self($f, $today, $preset, self::granularity($granularity, $f, $today));
    }

    private static function parseDate(?string $v): ?\DateTimeImmutable
    {
        if ($v === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return ($d && $d->format('Y-m-d') === $v) ? $d : null;
    }

    private static function granularity(?string $g, \DateTimeImmutable $f, \DateTimeImmutable $t): string
    {
        if ($g !== null && in_array($g, self::GRANULARITIES, true)) {
            return $g;
        }
        $days = self::daysBetween($f, $t);

        return $days <= 45 ? 'day' : ($days <= 200 ? 'week' : 'month');
    }

    private static function daysBetween(\DateTimeImmutable $f, \DateTimeImmutable $t): int
    {
        return (int) $f->diff($t)->days + 1;
    }

    public function days(): int
    {
        return self::daysBetween($this->from, $this->to);
    }

    /** Same-length period immediately before this one (for deltas). */
    public function previous(): self
    {
        $len = $this->days();
        $to = $this->from->modify('-1 day');

        return new self($to->modify('-' . ($len - 1) . ' days'), $to, $this->preset, $this->granularity);
    }

    public function fromSql(): string
    {
        return $this->from->format('Y-m-d 00:00:00');
    }

    public function toSql(): string
    {
        return $this->to->format('Y-m-d 23:59:59');
    }

    public static function sqlBucketFormat(string $granularity): string
    {
        return match ($granularity) {
            'week' => '%x-W%v',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };
    }

    public static function phpBucketFormat(string $granularity): string
    {
        return match ($granularity) {
            'week' => 'o-\WW',
            'month' => 'Y-m',
            default => 'Y-m-d',
        };
    }

    /** @return string[] ordered, gap-free bucket keys covering the period */
    public function buckets(): array
    {
        $fmt = self::phpBucketFormat($this->granularity);
        $step = match ($this->granularity) {
            'week' => '+1 week',
            'month' => '+1 month',
            default => '+1 day',
        };
        $cursor = match ($this->granularity) {
            'week' => $this->from->modify('monday this week'),
            'month' => $this->from->modify('first day of this month'),
            default => $this->from,
        };
        $keys = [];
        while ($cursor <= $this->to) {
            $keys[] = $cursor->format($fmt);
            $cursor = $cursor->modify($step);
        }

        return array_values(array_unique($keys));
    }
}

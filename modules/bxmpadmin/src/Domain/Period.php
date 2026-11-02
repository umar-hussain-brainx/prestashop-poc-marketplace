<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Domain;

/**
 * Reporting period (inclusive day bounds) resolved from a preset or a custom from/to pair,
 * plus the time buckets used by trend charts (day / week / month depending on the length).
 */
final class Period
{
    public const PRESETS = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'ytd' => 'Year to date',
        '12m' => 'Last 12 months',
        'all' => 'All time',
        'custom' => 'Custom',
    ];

    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly string $preset = 'custom',
    ) {
        if ($from > $to) {
            throw new \InvalidArgumentException('Period start must be before its end');
        }
    }

    public static function fromRequest(?string $preset, ?string $from = null, ?string $to = null, ?\DateTimeImmutable $now = null): self
    {
        $now = ($now ?? new \DateTimeImmutable())->setTime(0, 0);
        $preset = $preset !== null && isset(self::PRESETS[$preset]) ? $preset : '30d';

        switch ($preset) {
            case 'today':
                return new self($now, $now, $preset);
            case '7d':
                return new self($now->modify('-6 days'), $now, $preset);
            case '90d':
                return new self($now->modify('-89 days'), $now, $preset);
            case 'month':
                return new self($now->modify('first day of this month'), $now, $preset);
            case 'last_month':
                return new self($now->modify('first day of last month'), $now->modify('last day of last month'), $preset);
            case 'ytd':
                return new self($now->setDate((int) $now->format('Y'), 1, 1), $now, $preset);
            case '12m':
                return new self($now->modify('-1 year +1 day'), $now, $preset);
            case 'all':
                return new self(new \DateTimeImmutable('2000-01-01'), $now, $preset);
            case 'custom':
                $f = self::parseDate($from) ?? $now->modify('-29 days');
                $t = self::parseDate($to) ?? $now;
                if ($f > $t) {
                    [$f, $t] = [$t, $f];
                }

                return new self($f, $t, $preset);
            case '30d':
            default:
                return new self($now->modify('-29 days'), $now, '30d');
        }
    }

    private static function parseDate(?string $v): ?\DateTimeImmutable
    {
        if ($v === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d ?: null;
    }

    public function fromSql(): string
    {
        return $this->from->format('Y-m-d') . ' 00:00:00';
    }

    public function toSql(): string
    {
        return $this->to->format('Y-m-d') . ' 23:59:59';
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    /** day ≤ 62 days, week ≤ 1 year, month beyond. */
    public function granularity(): string
    {
        $d = $this->days();

        return $d <= 62 ? 'day' : ($d <= 371 ? 'week' : 'month');
    }

    /** Bucket key of a date for the current granularity (same format as {@see bucketSqlExpr()}). */
    public function bucketOf(\DateTimeInterface $d): string
    {
        return match ($this->granularity()) {
            'day' => $d->format('Y-m-d'),
            'week' => $d->format('o-\WW'),
            default => $d->format('Y-m'),
        };
    }

    /** MySQL expression producing the bucket key of a DATETIME column. */
    public function bucketSqlExpr(string $column): string
    {
        return match ($this->granularity()) {
            'day' => "DATE_FORMAT($column, '%Y-%m-%d')",
            'week' => "DATE_FORMAT($column, '%x-W%v')",
            default => "DATE_FORMAT($column, '%Y-%m')",
        };
    }

    /** @return string[] ordered, gap-free bucket keys covering the period */
    public function buckets(): array
    {
        $keys = [];
        $step = match ($this->granularity()) {
            'day' => '+1 day',
            'week' => '+1 day',
            default => 'first day of next month',
        };
        for ($d = $this->from; $d <= $this->to; $d = $d->modify($step)) {
            $keys[$this->bucketOf($d)] = true;
        }

        return array_keys($keys);
    }

    /** The period of the same length right before this one (for deltas). */
    public function previous(): self
    {
        $days = $this->days();

        return new self($this->from->modify('-' . $days . ' days'), $this->from->modify('-1 day'), 'custom');
    }

    public function label(): string
    {
        return $this->from->format('Y-m-d') . ' → ' . $this->to->format('Y-m-d');
    }

    public function contains(string $datetime): bool
    {
        return $datetime >= $this->fromSql() && $datetime <= $this->toSql();
    }
}

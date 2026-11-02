<?php

declare(strict_types=1);

namespace BrainX\Collections\Tests\Domain;

use BrainX\Collections\Domain\ScheduleResolver as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleResolverTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-02 12:00:00');
    }

    public static function cases(): array
    {
        return [
            'always on' => [true, null, null, S::LIVE],
            'disabled wins over window' => [false, '2026-11-01 00:00:00', '2026-12-31 23:59:59', S::DISABLED],
            'inside window' => [true, '2026-11-01 00:00:00', '2026-12-31 23:59:59', S::LIVE],
            'scheduled' => [true, '2027-03-20 00:00:00', '2027-04-10 23:59:59', S::SCHEDULED],
            'expired' => [true, '2026-10-01 00:00:00', '2026-11-01 23:59:59', S::EXPIRED],
            'open start' => [true, null, '2026-11-30 00:00:00', S::LIVE],
            'open end' => [true, '2026-01-01 00:00:00', null, S::LIVE],
            'start bound inclusive' => [true, '2026-11-02 12:00:00', null, S::LIVE],
            'end bound inclusive' => [true, null, '2026-11-02 12:00:00', S::LIVE],
            'one second late' => [true, null, '2026-11-02 11:59:59', S::EXPIRED],
            'zero dates are open' => [true, '0000-00-00 00:00:00', '0000-00-00 00:00:00', S::LIVE],
            'empty strings are open' => [true, '', '', S::LIVE],
            'bare end date = end of day' => [true, null, '2026-11-02', S::LIVE],
            'bare start date = start of day' => [true, '2026-11-03', null, S::SCHEDULED],
        ];
    }

    #[DataProvider('cases')]
    public function testStatus(bool $active, ?string $start, ?string $end, string $expected): void
    {
        self::assertSame($expected, S::status($active, $start, $end, self::now()));
        self::assertSame($expected === S::LIVE, S::isLive($active, $start, $end, self::now()));
    }

    public function testStatusOfRow(): void
    {
        self::assertSame(S::SCHEDULED, S::statusOf(['active' => '1', 'date_start' => '2027-01-01 00:00:00', 'date_end' => null], self::now()));
        self::assertSame(S::DISABLED, S::statusOf(['active' => '0'], self::now()));
    }

    public function testNextChange(): void
    {
        self::assertSame('2027-03-20 00:00:00', S::nextChange(true, '2027-03-20 00:00:00', '2027-04-10 23:59:59', self::now()));
        self::assertSame('2026-12-31 23:59:59', S::nextChange(true, '2026-11-01 00:00:00', '2026-12-31 23:59:59', self::now()));
        self::assertNull(S::nextChange(true, '2026-10-01 00:00:00', '2026-10-31 23:59:59', self::now()));
        self::assertNull(S::nextChange(false, '2027-03-20 00:00:00', null, self::now()));
    }

    public function testValidateWindow(): void
    {
        self::assertNull(S::validateWindow(null, null));
        self::assertNull(S::validateWindow('2026-11-01 00:00:00', '2026-11-01 00:00:00'));
        self::assertNotNull(S::validateWindow('2026-12-01 00:00:00', '2026-11-01 00:00:00'));
        self::assertNotNull(S::validateWindow('not a date', null));
        self::assertSame('2026-11-01 10:30:00', S::normalize('2026-11-01T10:30'));
    }
}

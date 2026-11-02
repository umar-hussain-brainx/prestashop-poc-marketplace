<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Tests\Domain;

use BrainX\MarketplaceAdmin\Domain\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-02 15:00:00');
    }

    public function testPresets(): void
    {
        $p = Period::fromRequest('30d', null, null, $this->now);
        self::assertSame('2026-10-04 00:00:00', $p->fromSql());
        self::assertSame('2026-11-02 23:59:59', $p->toSql());
        self::assertSame(30, $p->days());

        $lm = Period::fromRequest('last_month', null, null, $this->now);
        self::assertSame(['2026-10-01', '2026-10-31'], [$lm->from->format('Y-m-d'), $lm->to->format('Y-m-d')]);
        self::assertSame('2026-01-01', Period::fromRequest('ytd', null, null, $this->now)->from->format('Y-m-d'));
        self::assertSame(1, Period::fromRequest('today', null, null, $this->now)->days());
        self::assertSame('30d', Period::fromRequest('bogus', null, null, $this->now)->preset);
    }

    public function testCustomIsValidatedAndSwapped(): void
    {
        $p = Period::fromRequest('custom', '2026-10-20', '2026-10-01', $this->now);
        self::assertSame('2026-10-01', $p->from->format('Y-m-d'));
        self::assertSame('2026-10-20', $p->to->format('Y-m-d'));
        $bad = Period::fromRequest('custom', "2026-10-01' OR 1=1", 'x', $this->now);
        self::assertSame('2026-10-04', $bad->from->format('Y-m-d'));
    }

    public function testBucketsAndGranularity(): void
    {
        $p = Period::fromRequest('7d', null, null, $this->now);
        self::assertSame('day', $p->granularity());
        self::assertCount(7, $p->buckets());
        self::assertSame('2026-10-27', $p->buckets()[0]);

        $q = Period::fromRequest('90d', null, null, $this->now);
        self::assertSame('week', $q->granularity());
        self::assertSame('2026-W45', $q->bucketOf($this->now));
        self::assertContains('2026-W45', $q->buckets());

        $y = Period::fromRequest('all', null, null, $this->now);
        self::assertSame('month', $y->granularity());
        $b = $y->buckets();
        self::assertSame('2026-11', end($b));
    }

    public function testPreviousPeriod(): void
    {
        $prev = Period::fromRequest('7d', null, null, $this->now)->previous();
        self::assertSame(['2026-10-20', '2026-10-26'], [$prev->from->format('Y-m-d'), $prev->to->format('Y-m-d')]);
        self::assertTrue(Period::fromRequest('7d', null, null, $this->now)->contains('2026-11-02 23:00:00'));
    }
}

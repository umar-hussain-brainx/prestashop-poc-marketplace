<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Tests\Domain;

use BrainX\MarketplaceAdmin\Domain\SvgChart;
use PHPUnit\Framework\TestCase;

final class SvgChartTest extends TestCase
{
    public function testRendersEscapedSvg(): void
    {
        $svg = SvgChart::barLine(['2026-11-01' => 10.0, '<b>' => 25.0], ['2026-11-01' => 1.0, '<b>' => 2.5]);
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertSame(2, substr_count($svg, '<rect x=') - 1); // 2 bars + legend swatch
        self::assertStringNotContainsString('<b>', $svg);
        self::assertStringContainsString('<polyline', $svg);
        self::assertSame(50.0, SvgChart::niceCeil(41));
        self::assertStringContainsString('Vendor A', SvgChart::hbars(['Vendor A' => 3.0]));
    }
}

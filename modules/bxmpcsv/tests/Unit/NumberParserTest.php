<?php

declare(strict_types=1);

namespace BrainX\Csv\Tests\Unit;

use BrainX\Csv\Parser\NumberParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumberParserTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            ['12', null, 12.0],
            ['12.5', null, 12.5],
            ['12,5', null, 12.5],
            ['1.234,56', null, 1234.56],
            ['1,234.56', null, 1234.56],
            ['1 234,56', null, 1234.56],
            ["1\u{00A0}234,56", null, 1234.56],
            ["1'234.50", null, 1234.5],
            ['€ 12,50', null, 12.5],
            ['12.50 EUR', null, 12.5],
            ['1.234.567,8', null, 1234567.8],
            ['1,234,567', null, 1234567.0],
            ['0,99', ',', 0.99],
            ['.5', null, 0.5],
            ['-3,5', null, -3.5],
            // ambiguous single separator + 3 digits: resolved with the file hint
            ['1.000', ',', 1000.0],
            ['1.000', '.', 1.0],
            ['1,000', '.', 1000.0],
            ['1,000', ',', 1.0],
            ['1.000', null, 1.0],
            ['0.125', ',', 0.125],
        ];
    }

    #[DataProvider('numbers')]
    public function testParse(string $raw, ?string $hint, float $expected): void
    {
        self::assertEqualsWithDelta($expected, NumberParser::parse($raw, $hint), 1e-9, $raw);
    }

    public static function invalid(): array
    {
        return [[''], ['abc'], ['12a'], ['1.2.3,4,5'], ['1,23,456.7'], ['12..5'], ['1.234,5.6'], ['--1'], ['1e5']];
    }

    #[DataProvider('invalid')]
    public function testInvalid(string $raw): void
    {
        self::assertNull(NumberParser::parse($raw, ','));
    }

    public function testParseInt(): void
    {
        self::assertSame(12, NumberParser::parseInt('12'));
        self::assertSame(12, NumberParser::parseInt('12,00'));
        self::assertSame(1500, NumberParser::parseInt('1.500', ','));
        self::assertNull(NumberParser::parseInt('1,5', ','));
        self::assertNull(NumberParser::parseInt('x'));
        self::assertSame(-2, NumberParser::parseInt('-2'));
    }
}

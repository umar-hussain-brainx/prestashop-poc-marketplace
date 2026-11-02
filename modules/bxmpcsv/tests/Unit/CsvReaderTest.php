<?php

declare(strict_types=1);

namespace BrainX\Csv\Tests\Unit;

use BrainX\Csv\Parser\CsvException;
use BrainX\Csv\Parser\CsvReader;
use BrainX\Csv\Parser\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function file(string $content): string
    {
        $f = tempnam(sys_get_temp_dir(), 'bxcsv');
        file_put_contents($f, $content);
        $this->files[] = $f;

        return $f;
    }

    public function testSemicolonWithBomAndQuotedFields(): void
    {
        $r = new CsvReader($this->file("\xEF\xBB\xBFvendor_sku;title;price\nA1;\"Mug; red\";\"1,50\"\n\nA2;\"Line\nbreak \"\"quoted\"\"\";2\n"));
        self::assertSame(';', $r->delimiter());
        self::assertSame(',', $r->decimalHint());
        self::assertTrue($r->hadBom());
        self::assertSame(['vendor_sku', 'title', 'price'], $r->header());
        $rows = iterator_to_array($r->records());
        self::assertSame([2, 4], array_keys($rows), 'empty line skipped, numbering kept');
        self::assertSame(['A1', 'Mug; red', '1,50'], $rows[2]);
        self::assertSame("Line\nbreak \"quoted\"", $rows[4][1]);
        self::assertSame(2, $r->count());
    }

    public function testCommaDelimiterAndResume(): void
    {
        $r = new CsvReader($this->file("sku,price,qty\nA,\"1,234.50\",3\nB,2.5,4\nC,3,5\n"));
        self::assertSame(',', $r->delimiter());
        self::assertSame('.', $r->decimalHint());
        $rows = iterator_to_array($r->records(2));
        self::assertSame([4 => ['C', '3', '5']], $rows);
    }

    public function testTabAndPipeDetection(): void
    {
        self::assertSame("\t", (new CsvReader($this->file("a\tb\tc\n1\t2\t3\n")))->delimiter());
        self::assertSame('|', (new CsvReader($this->file("a|b|c\n1|2,5|3\n")))->delimiter());
    }

    public function testDetectionPrefersConsistentDelimiter(): void
    {
        // header has 2 commas (inside a quoted header) but semicolons are consistent
        self::assertSame(';', CsvReader::detectDelimiter(["sku;\"price, eur\";qty\n", "A;1,5;2\n", "B;2,5;3\n"]));
    }

    public function testWindows1252IsConverted(): void
    {
        $r = new CsvReader($this->file("sku;title\nA;Caf\xE9 cr\xE8me\n"));
        $rows = iterator_to_array($r->records());
        self::assertSame('Café crème', $rows[2][1]);
    }

    public function testUtf16Rejected(): void
    {
        $this->expectException(CsvException::class);
        new CsvReader($this->file("\xFF\xFEs\0k\0u\0"));
    }

    public function testEmptyFileRejected(): void
    {
        $this->expectException(CsvException::class);
        new CsvReader($this->file("\n\n"));
    }

    public function testWriterRoundTrip(): void
    {
        $csv = CsvWriter::toString(['a', 'b'], [['a' => 'x;y', 'b' => '=1+1'], ['a' => "multi\nline", 'b' => null]], ';', true);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $r = new CsvReader($this->file($csv));
        $rows = array_values(iterator_to_array($r->records()));
        self::assertSame(['x;y', "'=1+1"], $rows[0], 'formula injection neutralised');
        self::assertSame(["multi\nline", ''], $rows[1]);
    }
}

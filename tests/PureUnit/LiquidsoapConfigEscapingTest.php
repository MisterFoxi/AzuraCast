<?php

declare(strict_types=1);

namespace PureUnit;

use App\Radio\Backend\Liquidsoap\ConfigWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiquidsoapConfigEscapingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function userSuppliedValues(): iterable
    {
        yield 'password' => [
            "p\"ass\\word\nnext",
            '"p\\"ass\\\\word\\nnext"',
        ];
        yield 'charset' => [
            "UTF-8\"\runsafe",
            '"UTF-8\\"\\runsafe"',
        ];
        yield 'Stereo Tool path' => [
            "C:\\Stereo Tool\\preset \"radio\".sts",
            '"C:\\\\Stereo Tool\\\\preset \\"radio\\".sts"',
        ];
        yield 'Stereo Tool license' => [
            "license\"\nradio = unsafe",
            '"license\\"\\nradio = unsafe"',
        ];
    }

    #[DataProvider('userSuppliedValues')]
    public function testUserSuppliedValuesAreRawStringEscaped(string $value, string $expected): void
    {
        self::assertSame($expected, ConfigWriter::toRawString($value));
    }
}

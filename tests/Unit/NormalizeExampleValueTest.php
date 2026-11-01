<?php

namespace Tests\Unit;

use App\Actions\Engineering\NormalizeExampleValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizeExampleValueTest extends TestCase
{
    #[DataProvider('values')]
    public function test_it_collapses_whitespace_and_trims_the_result(string $input, string $expected): void
    {
        $normalized = (new NormalizeExampleValue)->execute($input);

        $this->assertSame($expected, $normalized);
    }

    public static function values(): array
    {
        return [
            'ordinary spaces' => ['  Udumbara    Internal Hub  ', 'Udumbara Internal Hub'],
            'tabs and newlines' => ["\tUdumbara\n\nInternal Hub\r\n", 'Udumbara Internal Hub'],
            'unicode separators' => ["\u{00A0}東京\u{2003}🚀\u{00A0}", '東京 🚀'],
            'whitespace only' => [" \t\n ", ''],
        ];
    }

    public function test_invalid_utf8_throws_an_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NormalizeExampleValue)->execute("invalid-\xFF-text");
    }
}

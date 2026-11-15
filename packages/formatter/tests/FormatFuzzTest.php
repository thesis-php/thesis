<?php

declare(strict_types=1);

namespace Thesis\Formatter;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Random strings of pieces that need escaping: the result of format() is a PHP literal of the same bytes,
 * valid UTF-8 where nothing is invisible but U+0020. To reproduce a failure, run the reported seed alone.
 */
#[Test]
final class FormatFuzzTest
{
    private const int CASES_PER_SEED = 2000;
    private const array PIECES = [
        'a', 'Z', '0', ' ', '\\', "'", '"', '$', '{', '}', "\n", "\r", "\t", "\0", "\e", "\x7F",
        "\x80", "\xBF", "\xC2", "\xD0", "\xE0", "\xED", "\xF0", "\xF4", "\xFF",
        'я', '€', '😀', "\u{85}", "\u{9B}", "\u{A0}", "\u{AD}", "\u{200B}", "\u{200D}", "\u{202E}", "\u{2028}",
        "\u{2066}", "\u{3000}", "\u{FEFF}", "\u{E0001}",
    ];

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 4, 5] as $seed) {
            yield "seed {$seed}" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function writesLiteralOfSameBytes(int $seed): void
    {
        $random = new Randomizer(new Mt19937($seed));

        for ($case = 0; $case < self::CASES_PER_SEED; ++$case) {
            $string = '';

            for ($count = $random->getInt(0, 30); $count > 0; --$count) {
                $string .= self::PIECES[$random->getInt(0, \count(self::PIECES) - 1)];
            }

            $formatted = format($string);
            $message = \sprintf('seed %d, case %d: %s', $seed, $case, bin2hex($string));

            Assert::same(actual: eval("return {$formatted};"), expected: $string, message: $message);
            Assert::same(
                actual: preg_match('/[\p{Cc}\p{Cf}\p{Z}]/u', str_replace(' ', '', $formatted)),
                expected: 0,
                message: $message,
            );
        }
    }
}

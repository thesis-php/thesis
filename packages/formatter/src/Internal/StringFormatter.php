<?php

declare(strict_types=1);

namespace Thesis\Formatter\Internal;

/**
 * Writes a string as a PHP literal of the same bytes. It is single-quoted while every character shows as itself,
 * otherwise double-quoted with an escape for each byte that is not UTF-8 and each character that does not show
 * or does not show as itself: control, format (bidi, zero-width) and space other than U+0020.
 *
 * @internal
 */
final class StringFormatter
{
    /**
     * The pattern matches a well-formed UTF-8 character (Unicode Standard, table 3-7) or a single byte.
     */
    private const string CHARACTER = <<<'REGEX'
        /[\x00-\x7F]
        |[\xC2-\xDF][\x80-\xBF]
        |\xE0[\xA0-\xBF][\x80-\xBF]
        |[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
        |\xED[\x80-\x9F][\x80-\xBF]
        |\xF0[\x90-\xBF][\x80-\xBF]{2}
        |[\xF1-\xF3][\x80-\xBF]{3}
        |\xF4[\x80-\x8F][\x80-\xBF]{2}
        |[\x80-\xFF]/x
        REGEX;
    private const string INVISIBLE = '/^[\p{Cc}\p{Cf}\p{Z}]$/u';
    private const array NAMED_ESCAPES = [
        "\t" => '\t',
        "\n" => '\n',
        "\v" => '\v',
        "\e" => '\e',
        "\f" => '\f',
        "\r" => '\r',
    ];
    private const array DOUBLE_QUOTED_ESCAPES = ['\\' => '\\\\', '"' => '\"', '$' => '\$'];

    /**
     * Shows at most $maxCharacters, and only those that fit into $maxLength bytes inside the quotes. A quote,
     * a backslash and a dollar count as two bytes, since one of the quotings escapes them. A cut literal
     * is followed by "...".
     *
     * @param non-negative-int $maxCharacters
     * @param int $maxLength nothing is shown below 1
     * @return non-empty-string
     */
    public static function format(string $string, int $maxCharacters, int $maxLength): string
    {
        preg_match_all(self::CHARACTER, self::head($string, $maxCharacters), $matches);

        $shown = '';
        $doubleQuoted = '';
        $length = 0;
        $isEscaped = false;

        foreach ($matches[0] as $count => $character) {
            $escape = self::escape($character);
            $characterLength = match (true) {
                $escape !== null => \strlen($escape),
                str_contains('\'"\$', $character) => 2,
                default => \strlen($character),
            };

            if ($count === $maxCharacters || $length + $characterLength > $maxLength) {
                break;
            }

            $shown .= $character;
            // A character that double quotes escape shows as itself, so it has no escape of its own to swap with.
            // @infection-ignore Coalesce
            $doubleQuoted .= $escape ?? self::DOUBLE_QUOTED_ESCAPES[$character] ?? $character;
            $length += $characterLength;
            $isEscaped = $isEscaped || $escape !== null;
        }

        $literal = $isEscaped ? '"' . $doubleQuoted . '"' : self::singleQuoted($shown);

        return $shown === $string ? $literal : $literal . '...';
    }

    /**
     * The bytes that may hold the first $maxCharacters: only the shown part is split, never the whole string.
     * Whether the string goes on is told by comparing it with the shown part.
     */
    private static function head(string $string, int $maxCharacters): string
    {
        // More bytes than the characters may take are split for nothing: only slower.
        // @infection-ignore IncrementInteger, UnwrapSubstr
        return substr($string, 0, 4 * $maxCharacters); // a character takes 4 bytes at most
    }

    /**
     * @return ?non-empty-string null if the character shows as itself
     */
    private static function escape(string $character): ?string
    {
        if ($character === ' ') {
            return null;
        }

        if (\strlen($character) === 1 && \ord($character[0]) >= 0x80) {
            return \sprintf('\x%02X', \ord($character[0])); // a byte that is not UTF-8
        }

        if (preg_match(self::INVISIBLE, $character) !== 1) {
            return null;
        }

        if (\strlen($character) === 1) {
            return self::NAMED_ESCAPES[$character] ?? \sprintf('\x%02X', \ord($character[0]));
        }

        return \sprintf('\u{%X}', self::codePoint($character));
    }

    /**
     * @return non-empty-string
     */
    private static function singleQuoted(string $string): string
    {
        // A backslash is escaped only where PHP would read it as an escape: before a quote, a backslash or the end.
        return "'" . preg_replace("/'|\\\\(?=['\\\\]|\\z)/", '\\\$0', $string) . "'";
    }

    /**
     * Decodes a well-formed UTF-8 character of 2 to 4 bytes: the first byte holds the high bits of the code point
     * after its length prefix (5, 4 or 3 bits), each next byte holds 6 bits after 10.
     */
    private static function codePoint(string $character): int
    {
        $length = \strlen($character);
        // The mask only clears the length prefix: its lowest bit is shifted out,
        // and the bit after the prefix is 0 in a well-formed character.
        // @infection-ignore DecrementInteger
        $codePoint = \ord($character[0]) & (0xFF >> ($length + 1));

        for ($i = 1; $i < $length; ++$i) {
            $codePoint = ($codePoint << 6) | (\ord($character[$i]) & 0x3F);
        }

        return $codePoint;
    }
}

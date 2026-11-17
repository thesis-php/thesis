<?php

declare(strict_types=1);

namespace Thesis\Formatter\Internal;

use function Thesis\Formatter\formatFunction;

/**
 * Writes a value in the notation of PHPStan, as short as an error message needs. Items of arrays and objects
 * stop once the output reaches MAX_LENGTH, and a string shows only what is left of it, so the output goes past it
 * by the punctuation around the last item and by a name of a class or a closure at most. A container deeper than
 * MAX_DEPTH is written as {...}, which also ends a cycle of objects or references.
 *
 * @internal
 */
final class ValueFormatter
{
    private const int MAX_LENGTH = 500;
    private const int MAX_DEPTH = 3;
    private const int MAX_STRING_CHARACTERS = 100;
    private const string IDENTIFIER = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

    /**
     * @return non-empty-string
     */
    public static function format(mixed $value): string
    {
        /** @var non-empty-string */
        return self::write($value, depth: 0, maxLength: self::MAX_LENGTH);
    }

    /**
     * @param int $maxLength what is left of MAX_LENGTH before this value, negative once it is used up
     */
    private static function write(mixed $value, int $depth, int $maxLength): string
    {
        return match (true) {
            \is_array($value) => self::items(
                kind: array_is_list($value) && $value !== [] ? 'list' : 'array',
                items: $value,
                depth: $depth,
                maxLength: $maxLength,
            ),
            \is_object($value) && $value::class === \stdClass::class => self::items(
                kind: 'object',
                items: get_object_vars($value),
                depth: $depth,
                maxLength: $maxLength,
            ),
            \is_string($value) => self::string($value, $maxLength),
            default => self::single($value),
        };
    }

    private static function single(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            \is_float($value) => var_export($value, return: true),
            $value instanceof \UnitEnum => className($value::class) . '::' . $value->name,
            $value instanceof \Closure => formatFunction($value),
            \is_object($value) => className($value::class),
            default => get_debug_type($value), // a resource, open or closed
        };
    }

    /**
     * @param 'list'|'array'|'object' $kind
     * @param array<mixed> $items
     */
    private static function items(string $kind, array $items, int $depth, int $maxLength): string
    {
        $output = $kind . '{';

        if ($items !== [] && $depth >= self::MAX_DEPTH) {
            return $output . '...}';
        }

        $isFirst = true;

        foreach ($items as $key => $item) {
            if (!$isFirst) {
                $output .= ', ';
            }

            if (\strlen($output) >= $maxLength) {
                $output .= '...';

                break;
            }

            if ($kind !== 'list') {
                $output .= self::key($key, $maxLength - \strlen($output)) . ': ';
            }

            $output .= self::write($item, $depth + 1, $maxLength - \strlen($output));
            $isFirst = false;
        }

        return $output . '}';
    }

    private static function key(int|string $key, int $maxLength): string
    {
        if (\is_int($key) || preg_match(self::IDENTIFIER, $key) === 1) {
            return (string) $key;
        }

        return self::string($key, $maxLength);
    }

    private static function string(string $string, int $maxLength): string
    {
        return StringFormatter::format(
            string: $string,
            maxCharacters: self::MAX_STRING_CHARACTERS,
            maxLength: max(0, $maxLength),
        );
    }
}

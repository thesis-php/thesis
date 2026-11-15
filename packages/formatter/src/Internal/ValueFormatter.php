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

    private string $output = '';

    /**
     * @return non-empty-string
     */
    public static function format(mixed $value): string
    {
        $formatter = new self();
        $formatter->write($value, depth: 0);

        /** @var non-empty-string */
        return $formatter->output;
    }

    private function __construct() {}

    private function write(mixed $value, int $depth): void
    {
        if (\is_array($value)) {
            $kind = array_is_list($value) && $value !== [] ? 'list' : 'array';
            $this->items($kind, $value, $depth);
        } elseif (\is_object($value) && $value::class === \stdClass::class) {
            $this->items('object', get_object_vars($value), $depth);
        } elseif (\is_string($value)) {
            $this->string($value);
        } else {
            $this->output .= self::single($value);
        }
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
    private function items(string $kind, array $items, int $depth): void
    {
        $this->output .= $kind . '{';

        if ($items !== [] && $depth >= self::MAX_DEPTH) {
            $this->output .= '...}';

            return;
        }

        $isFirst = true;

        foreach ($items as $key => $item) {
            if (!$isFirst) {
                $this->output .= ', ';
            }

            if (\strlen($this->output) >= self::MAX_LENGTH) {
                $this->output .= '...';

                break;
            }

            if ($kind !== 'list') {
                $this->key($key);
                $this->output .= ': ';
            }

            $this->write($item, $depth + 1);
            $isFirst = false;
        }

        $this->output .= '}';
    }

    private function key(int|string $key): void
    {
        if (\is_int($key) || preg_match(self::IDENTIFIER, $key) === 1) {
            $this->output .= $key;
        } else {
            $this->string($key);
        }
    }

    private function string(string $string): void
    {
        $this->output .= StringFormatter::format(
            string: $string,
            maxCharacters: self::MAX_STRING_CHARACTERS,
            maxLength: max(0, self::MAX_LENGTH - \strlen($this->output)),
        );
    }
}

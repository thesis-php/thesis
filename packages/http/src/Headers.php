<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * A `Headers` object holds immutable message headers or trailers. It treats names as case-insensitive
 * and stores them in lowercase, as HTTP/2 requires. A name may have several lines. Iteration yields
 * each name with all its lines, in the order the names were added.
 *
 * A name must be a token, and a value must have no control characters (CR, LF, NUL and the others),
 * so user input cannot add a line to a message. Spaces and tabs around a value are trimmed.
 *
 * @api
 * @implements \IteratorAggregate<non-empty-lowercase-string, non-empty-list<string>>
 */
final class Headers implements \IteratorAggregate
{
    private const int QUOTE_LENGTH = 64;
    private const string TOKEN_CHARS = '!#$%&\'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * DEL and every control character except the tab.
     */
    private const string FIELD_VALUE_FORBIDDEN_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    public bool $isEmpty {
        get => $this->lines === [];
    }

    /**
     * PHP turns a numeric name into an int key, whatever the type says.
     *
     * @var array<non-empty-lowercase-string, non-empty-list<string>>
     */
    private readonly array $lines;

    /**
     * @param iterable<string, string|list<string>> $fields if a name is given twice (`Accept` and `accept`),
     *     the lines of both are kept
     * @throws \ValueError if a name or a value is not valid
     */
    public function __construct(iterable $fields = [])
    {
        if ($fields === []) {
            $this->lines = [];

            return;
        }

        if (\is_array($fields) && \count($fields) === 1) {
            $key = array_key_first($fields);
            $value = $fields[$key];
            if (\is_string($value)) {
                // @phpstan-ignore cast.useless (PHP turns a numeric name into an int key)
                $this->lines = self::single((string) $key, $value);

                return;
            }
        }

        $lines = [];

        foreach ($fields as $name => $values) {
            // @phpstan-ignore cast.useless (PHP turns a numeric name into an int key)
            $name = self::name((string) $name);

            foreach (self::values($name, $values) as $value) {
                $lines[$name][] = $value;
            }
        }

        $this->lines = $lines;
    }

    /**
     * PHP represents numeric field names as integer keys.
     *
     * @return array<int|non-empty-lowercase-string, non-empty-list<string>>
     */
    public function toArray(): array
    {
        return $this->lines;
    }

    public function has(string $name): bool
    {
        return isset($this->lines[strtolower($name)]);
    }

    /**
     * `get()` returns the field value or null if the field is absent. It joins multiple lines with a comma
     * as RFC 9110 defines. Use {@see all()} for `set-cookie`, whose lines cannot be joined.
     */
    public function get(string $name): ?string
    {
        $lines = $this->lines[strtolower($name)] ?? null;

        if ($lines === null) {
            return null;
        }

        return implode(', ', $lines);
    }

    /**
     * @return list<string> the lines of a field in the order they were added
     */
    public function all(string $name): array
    {
        return $this->lines[strtolower($name)] ?? [];
    }

    /**
     * `with()` replaces the lines of a field. The field keeps its position. An empty list removes the field.
     *
     * @param string|list<string> $values
     * @throws \ValueError if the name or a value is not valid
     */
    public function with(string $name, string|array $values): self
    {
        $name = self::name($name);
        $lines = $this->lines;
        $values = self::values($name, $values);

        if ($values === []) {
            unset($lines[$name]);
        } else {
            $lines[$name] = $values;
        }

        return clone($this, ['lines' => $lines]);
    }

    /**
     * `withAdded()` adds lines to a field after its existing lines.
     *
     * @param string|list<string> $values
     * @throws \ValueError if the name or a value is not valid
     */
    public function withAdded(string $name, string|array $values): self
    {
        $name = self::name($name);
        $lines = $this->lines;

        foreach (self::values($name, $values) as $value) {
            $lines[$name][] = $value;
        }

        return clone($this, ['lines' => $lines]);
    }

    /**
     * @no-named-arguments
     */
    public function without(string ...$names): self
    {
        $lines = $this->lines;

        foreach ($names as $name) {
            unset($lines[strtolower($name)]);
        }

        return clone($this, ['lines' => $lines]);
    }

    /**
     * @return \Generator<non-empty-lowercase-string, non-empty-list<string>>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->lines as $name => $lines) {
            // @phpstan-ignore cast.useless (PHP turns a numeric name into an int key)
            yield (string) $name => $lines;
        }
    }

    /**
     * @return non-empty-lowercase-string
     * @throws \ValueError
     */
    private static function name(string $name): string
    {
        if ($name === '' || strspn($name, self::TOKEN_CHARS) !== \strlen($name)) {
            throw new \ValueError(\sprintf('A header name must be a token, got %s.', self::quote($name)));
        }

        return strtolower($name);
    }

    /**
     * @param string|list<string> $values
     * @return list<string>
     * @throws \ValueError
     */
    private static function values(string $name, string|array $values): array
    {
        $trimmed = [];

        foreach ((array) $values as $value) {
            $trimmed[] = self::value($name, $value);
        }

        return $trimmed;
    }

    /**
     * @return array<non-empty-lowercase-string, non-empty-list<string>>
     */
    private static function single(string $name, string $value): array
    {
        /** @var ?string $lastName */
        static $lastName = null;
        /** @var ?string $lastValue */
        static $lastValue = null;
        /** @var array<non-empty-lowercase-string, non-empty-list<string>> $lines */
        static $lines = [];
        if ($name === $lastName && $value === $lastValue) {
            return $lines;
        }

        $normalized = self::name($name);
        $result = [$normalized => [self::value($normalized, $value)]];
        if (\strlen($name) + \strlen($value) <= 1024) {
            $lastName = $name;
            $lastValue = $value;
            $lines = $result;
        }

        return $result;
    }

    private static function value(string $name, string $value): string
    {
        if (strcspn($value, self::FIELD_VALUE_FORBIDDEN_CHARS) !== \strlen($value)) {
            throw new \ValueError(\sprintf(
                'A value of the header "%s" must have no control characters, got %s.',
                $name,
                self::quote($value),
            ));
        }

        return trim($value, " \t");
    }

    private static function quote(string $bytes): string
    {
        if (\strlen($bytes) > self::QUOTE_LENGTH) {
            $bytes = substr($bytes, 0, self::QUOTE_LENGTH) . '...';
        }

        return '"' . addcslashes($bytes, "\0..\37\"\\\177..\377") . '"';
    }
}

/** @api */
const EMPTY_HEADERS = new Headers();

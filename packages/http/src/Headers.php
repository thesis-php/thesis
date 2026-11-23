<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Http\Internal\Http1\Fields;
use function Thesis\Http\Internal\isFieldValue;
use function Thesis\Http\Internal\isToken;
use function Thesis\Http\Internal\quote;

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
final readonly class Headers implements \IteratorAggregate
{
    /**
     * Field names and values were checked by the HTTP/1 parser.
     *
     * @internal
     */
    public static function fromHttp1(Fields $fields, bool $withoutHost = false): self
    {
        /** @var self $empty */
        static $empty = new self();
        $lines = $fields->endToEnd;

        if ($withoutHost) {
            unset($lines['host']);
        }

        return $lines === [] ? $empty : clone($empty, ['lines' => $lines]);
    }

    /**
     * PHP turns a numeric name into an int key, whatever the type says.
     *
     * @var array<non-empty-lowercase-string, non-empty-list<string>>
     */
    private array $lines;

    /**
     * @param iterable<string, string|list<string>> $fields if a name is given twice (`Accept` and `accept`),
     *     the lines of both are kept
     * @throws \ValueError if a name or a value is not valid
     */
    public function __construct(iterable $fields = [])
    {
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
     * @internal
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
        if (!isToken($name)) {
            throw new \ValueError(\sprintf('A header name must be a token, got %s.', quote($name)));
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
            if (!isFieldValue($value)) {
                throw new \ValueError(\sprintf(
                    'A value of the header "%s" must have no control characters, got %s.',
                    $name,
                    quote($value),
                ));
            }

            $trimmed[] = trim($value, " \t");
        }

        return $trimmed;
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * Ordered, case-sensitive URI query parameters. Iteration yields every name and value, including repeats.
 * Names and values are percent-decoded. A plus sign stays a plus sign.
 *
 * @api
 * @phpstan-type QueryInput string|array<int|non-empty-string, string|list<string>>|Query
 * @implements \IteratorAggregate<string, string>
 */
final readonly class Query implements \IteratorAggregate
{
    /**
     * @var list<array{string, string}>
     */
    private array $pairs;

    /**
     * @param iterable<int|non-empty-string, string|list<string>> $parameters
     */
    public function __construct(iterable $parameters = [])
    {
        $pairs = [];

        foreach ($parameters as $name => $values) {
            foreach ((array) $values as $value) {
                $pairs[] = [(string) $name, $value];
            }
        }

        $this->pairs = $pairs;
    }

    /**
     * Parses the raw query component without the leading question mark.
     */
    public static function parse(string $query): self
    {
        /** @var self $empty */
        static $empty = new self();
        if ($query === '') {
            return $empty;
        }

        $pairs = [];

        foreach (explode('&', $query) as $field) {
            if ($field === '') {
                continue;
            }

            $parts = explode('=', $field, 2);
            $pairs[] = [rawurldecode($parts[0]), rawurldecode($parts[1] ?? '')];
        }

        return clone($empty, ['pairs' => $pairs]);
    }

    public function get(string $name): ?string
    {
        foreach ($this->pairs as [$key, $value]) {
            if ($key === $name) {
                return $value;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * @return list<string>
     */
    public function all(string $name): array
    {
        $values = [];

        foreach ($this->pairs as [$key, $value]) {
            if ($key === $name) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array<int|string, non-empty-list<string>>
     */
    public function toArray(): array
    {
        $parameters = [];

        foreach ($this->pairs as [$name, $value]) {
            $parameters[$name][] = $value;
        }

        return $parameters;
    }

    /**
     * Replaces every occurrence at the position of the first. An empty list removes the parameter.
     *
     * @param non-empty-string $name
     * @param string|list<string> $values
     */
    public function with(string $name, string|array $values): self
    {
        $pairs = [];
        $replacement = [];

        foreach ((array) $values as $value) {
            $replacement[] = [$name, $value];
        }

        foreach ($this->pairs as $pair) {
            if ($pair[0] === $name) {
                array_push($pairs, ...$replacement);
                $replacement = [];
            } else {
                $pairs[] = $pair;
            }
        }

        array_push($pairs, ...$replacement);

        return clone($this, ['pairs' => $pairs]);
    }

    /**
     * Appends values after the last pair in the query.
     *
     * @param non-empty-string $name
     * @param string|list<string> $values
     */
    public function withAdded(string $name, string|array $values): self
    {
        $pairs = $this->pairs;
        foreach ((array) $values as $value) {
            $pairs[] = [$name, $value];
        }

        return clone($this, ['pairs' => $pairs]);
    }

    /**
     * @no-named-arguments
     */
    public function without(string ...$names): self
    {
        $pairs = [];
        foreach ($this->pairs as $pair) {
            if (!\in_array($pair[0], $names, true)) {
                $pairs[] = $pair;
            }
        }

        return clone($this, ['pairs' => $pairs]);
    }

    public function toString(): string
    {
        $fields = [];

        foreach ($this->pairs as [$name, $value]) {
            $fields[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $fields);
    }

    /**
     * @return \Generator<string, string>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->pairs as [$name, $value]) {
            yield $name => $value;
        }
    }
}

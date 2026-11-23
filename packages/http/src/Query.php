<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * Ordered, case-sensitive URI query parameters. Iteration yields every name and value, including repeats.
 * Names and values are percent-decoded. A plus sign stays a plus sign.
 *
 * @api
 * @implements \IteratorAggregate<string, string>
 */
final readonly class Query implements \IteratorAggregate
{
    /**
     * @var list<array{string, string}>
     */
    private array $pairs;

    /**
     * @param iterable<int|string, string|list<string>> $parameters
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
        $pairs = [];

        foreach (explode('&', $query) as $field) {
            if ($field === '') {
                continue;
            }

            $parts = explode('=', $field, 2);
            $pairs[] = [rawurldecode($parts[0]), rawurldecode($parts[1] ?? '')];
        }

        return clone(new self(), ['pairs' => $pairs]);
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

<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\ScopedValue;

/**
 * Storage of {@see ScopedValue}s: an immutable map, per fiber, from the spl_object_id() of a value to the value object
 * and what it is bound to. The object is kept next to its binding,
 * so its id cannot be reused while it is bound anywhere.
 *
 * A separate axis from the scope tree. A task's scope is a child of its group's tasks scope, not of the place
 * where it was spawned, so the values are captured by {@see capture()} in the spawning fiber instead,
 * and the task runs with them through {@see run()}.
 *
 * @internal
 * @phpstan-type Values array<int, array{ScopedValue<mixed>, mixed}>
 */
final class Context
{
    /**
     * @return Values
     */
    public static function capture(): array
    {
        /** @var Values */
        return CONTEXT[fiberKey()] ?? [];
    }

    /**
     * @template R
     * @param Values $values from {@see capture()}
     * @param callable(): R $fn
     * @return R
     */
    public static function run(array $values, callable $fn): mixed
    {
        return self::bind(
            values: $values,
            previous: self::capture(),
            fn: $fn,
        );
    }

    /**
     * @param ScopedValue<mixed> $key
     * @return mixed what $key is bound to, even if it is null, or $default if it is not bound
     */
    public static function get(ScopedValue $key, mixed $default): mixed
    {
        // Not through capture(): it is read on every get(), one call less.
        /** @var \WeakMap<object, Values> $contexts */
        $contexts = CONTEXT;
        $binding = $contexts[fiberKey()][spl_object_id($key)] ?? null;

        return $binding === null ? $default : $binding[1];
    }

    /**
     * @param ScopedValue<mixed> $key
     */
    public static function isBound(ScopedValue $key): bool
    {
        /** @var \WeakMap<object, Values> $contexts */
        $contexts = CONTEXT;

        return isset($contexts[fiberKey()][spl_object_id($key)]);
    }

    /**
     * @template R
     * @param ScopedValue<mixed> $key
     * @param callable(): R $fn
     * @return R
     */
    public static function with(ScopedValue $key, mixed $value, callable $fn): mixed
    {
        $previous = self::capture();
        // A copy: $previous, and what a task has captured before, are not affected.
        $values = $previous;
        $values[spl_object_id($key)] = [$key, $value];

        return self::bind(
            values: $values,
            previous: $previous,
            fn: $fn,
        );
    }

    /**
     * @template R
     * @param Values $values
     * @param Values $previous what is bound now, restored when $fn ends
     * @param callable(): R $fn
     * @return R
     */
    private static function bind(array $values, array $previous, callable $fn): mixed
    {
        // A constant cannot be written to by offset, its object can.
        $context = CONTEXT;
        $context[fiberKey()] = $values;

        try {
            return $fn();
        } finally {
            // fiberKey() again, not kept in a variable while $fn may be suspended: see fiberKey().
            if ($previous === []) {
                unset($context[fiberKey()]);
            } else {
                $context[fiberKey()] = $previous;
            }
        }
    }
}

/**
 * The values bound in every fiber that has bound any, keyed by {@see fiberKey()}.
 * Declared next to the class: it is read only by Context, so loading the class defines it.
 *
 * @internal
 * @var \WeakMap<object, array<int, array{ScopedValue<mixed>, mixed}>>
 */
const CONTEXT = new \WeakMap();

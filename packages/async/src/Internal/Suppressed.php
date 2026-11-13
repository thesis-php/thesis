<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * Failures that a group could not rethrow because an earlier one is rethrown instead, keyed by that earlier one.
 *
 * The rethrown error itself is not touched: neither its type nor its `previous` changes, so `catch` by type
 * works as before. Its entry goes away with it. Nested groups give a tree by themselves: the error of an inner group
 * leaves it with its own list and is recorded in the outer one as any other failure.
 *
 * @internal
 */
final class Suppressed
{
    public static function add(\Throwable $first, \Throwable $error): void
    {
        // The same object reaches a group more than once: the body awaits a failed task and rethrows its error.
        if ($error === $first) {
            return;
        }

        // A constant cannot be written to by offset, its object can.
        /** @var \WeakMap<\Throwable, list<\Throwable>> $suppressed */
        $suppressed = SUPPRESSED;
        $list = $suppressed[$first] ?? [];

        if (!\in_array($error, $list, true)) {
            $list[] = $error;
            $suppressed[$first] = $list;
        }
    }

    /**
     * @return list<\Throwable>
     */
    public static function of(\Throwable $error): array
    {
        /** @var \WeakMap<\Throwable, list<\Throwable>> $suppressed */
        $suppressed = SUPPRESSED;

        return $suppressed[$error] ?? [];
    }
}

/**
 * Declared next to the class: it is read only by Suppressed, so loading the class defines it.
 *
 * @internal
 * @var \WeakMap<\Throwable, list<\Throwable>>
 */
const SUPPRESSED = new \WeakMap();

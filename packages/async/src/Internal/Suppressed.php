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
        /** @var \WeakMap<\Throwable, \SplObjectStorage<\Throwable, null>> $suppressed */
        $suppressed = SUPPRESSED;
        // An object, not a list: neither a lookup nor a copy per add, so thousands of failures stay linear.
        $errors = $suppressed[$first] ?? null;

        if ($errors === null) {
            /** @var \SplObjectStorage<\Throwable, null> */
            $errors = new \SplObjectStorage();
            $suppressed[$first] = $errors;
        }

        $errors->offsetSet($error);
    }

    /**
     * @return list<\Throwable>
     */
    public static function of(\Throwable $error): array
    {
        /** @var \WeakMap<\Throwable, \SplObjectStorage<\Throwable, null>> $suppressed */
        $suppressed = SUPPRESSED;
        $errors = $suppressed[$error] ?? null;

        return $errors === null ? [] : iterator_to_array($errors, false);
    }
}

/**
 * Declared next to the class: it is read only by Suppressed, so loading the class defines it.
 *
 * @internal
 * @var \WeakMap<\Throwable, \SplObjectStorage<\Throwable, null>> in the order of addition
 */
const SUPPRESSED = new \WeakMap();

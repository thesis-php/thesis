<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * A group rethrows the first failure. This map stores the other failures under the error the group rethrows.
 *
 * The rethrown error itself is not touched: neither its type nor its `previous` changes, so `catch` by type
 * works as before. Its entry goes away with it. Nested groups give a tree by themselves: the error of an inner group
 * leaves it with its own list and is recorded in the outer one as any other failure. It stays a tree: see detach().
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
        $suppressed = SUPPRESSED;
        // An object, not a list: neither a lookup nor a copy per add, so thousands of failures stay linear.
        $errors = $suppressed[$first] ?? null;
        // $error may already hold $first: one failed Deferred awaited from an inner and an outer group
        // is recorded under the inner group's error, which then reaches the outer group after it.
        self::detach(suppressed: $suppressed, root: $error, error: $first);

        if ($errors === null) {
            /** @var \SplObjectStorage<\Throwable, null> */
            $errors = new \SplObjectStorage();
            $suppressed[$first] = $errors;
        }

        $errors->offsetSet($error);
    }

    /**
     * `detach()` removes $error from the tree under $root, so that recording $root under $error makes no cycle.
     * The caller rethrows $error, and all its suppressed failures stay reachable from $error. A leaf costs one lookup.
     *
     * @param \WeakMap<\Throwable, \SplObjectStorage<\Throwable, null>> $suppressed
     */
    private static function detach(\WeakMap $suppressed, \Throwable $root, \Throwable $error): void
    {
        /** @var \SplObjectStorage<\Throwable, null> */
        $visited = new \SplObjectStorage();
        $stack = [$root];

        while (($node = array_pop($stack)) !== null) {
            $errors = $suppressed[$node] ?? null;

            if ($errors === null) {
                continue;
            }

            $errors->offsetUnset($error);

            foreach ($errors as $child) {
                if (!$visited->offsetExists($child)) {
                    // Only spares a second walk under an error recorded in two groups: the result is the same.
                    // @infection-ignore MethodCallRemoval
                    $visited->offsetSet($child);
                    $stack[] = $child;
                }
            }
        }
    }

    /**
     * @return list<\Throwable>
     */
    public static function of(\Throwable $error): array
    {
        $errors = SUPPRESSED[$error] ?? null;

        return $errors === null ? [] : [...$errors];
    }
}

/**
 * Declared next to the class: it is read only by Suppressed, so loading the class defines it.
 *
 * @internal
 * @var \WeakMap<\Throwable, \SplObjectStorage<\Throwable, null>> in the order of addition
 */
const SUPPRESSED = new \WeakMap();

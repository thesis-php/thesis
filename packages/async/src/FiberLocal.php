<?php

declare(strict_types=1);

namespace Thesis\Async;

use const Thesis\Async\Internal\MAIN_KEY;

/**
 * A value bound per fiber, {main} included, for the time a callable runs: context that has to follow the code
 * without being passed as an argument (a request id for logs, a tenant, a transaction).
 *
 * There is no set(): a value is bound by {@see with()} and the previous one is restored when $fn ends,
 * whatever it ends with. So a value never outlives its with(), even in a fiber that is reused:
 * tasks and event loop callbacks that never suspend run one after another in the same Revolt fiber.
 *
 * Nothing is inherited: a task of a {@see Group} starts without a value, bind it again inside the task.
 *
 * Why not Revolt's FiberLocal: get() is on the hot path, the library itself keeps the current scope here and reads it
 * in every suspend(). Revolt's get() makes two WeakMap lookups (fiber → storage, then instance → value) and wraps
 * the value in an array; this one makes a single lookup, ~0.9 µs (~8%) cheaper per wait. Revolt's automatic clearing
 * after each event loop callback buys nothing either: {@see with()} restores the previous value itself.
 *
 * @api
 * @template T
 */
final class FiberLocal
{
    /**
     * @var \WeakMap<\Fiber, T>
     * @phpstan-ignore missingType.generics
     */
    private \WeakMap $values;

    public function __construct()
    {
        $this->values = new \WeakMap();
    }

    /**
     * @return ?T null outside of {@see with()}
     */
    public function get(): mixed
    {
        return $this->values[\Fiber::getCurrent() ?? MAIN_KEY] ?? null;
    }

    /**
     * Runs $fn with $value bound in the current fiber, then restores the previous value.
     *
     * @template R
     * @param T $value
     * @param callable(): R $fn
     * @return R
     */
    public function with(mixed $value, callable $fn): mixed
    {
        $previous = $this->get();
        $this->values[\Fiber::getCurrent() ?? MAIN_KEY] = $value;

        try {
            return $fn();
        } finally {
            // Not kept in a variable: a fiber suspended in $fn would hold itself through its own stack
            // and outlive its last reference until the cycle collector runs.
            $fiber = \Fiber::getCurrent() ?? MAIN_KEY;

            if ($previous === null) {
                unset($this->values[$fiber]);
            } else {
                $this->values[$fiber] = $previous;
            }
        }
    }
}

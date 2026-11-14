<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\Context;

/**
 * A value bound for the time a callable runs and inherited by every task started inside:
 * context of a request (a trace id, a logger context, a tenant) that has to reach all of its tasks.
 *
 * A task takes the values that are bound where {@see Group::spawn()} or {@see Group::daemon()} is called,
 * not where its group was created: `with()` around a spawn() is visible to the task. Binding a value later,
 * in the parent or in the task, does not affect the other one.
 * {@see shield()} does not hide values: it only hides cancellation.
 *
 * The object itself is the key, compared by identity: create one per kind of value, as a constant or a static property.
 * There is no set(): a value never outlives its with(), even in a fiber that is reused,
 * since tasks and event loop callbacks that never suspend run one after another in the same Revolt fiber.
 *
 * Outside of any with(), {@see get()} returns the default. A bound value is returned as is, even if it is null:
 * `with(null, ...)` hides an inherited value from the code inside when the default is not null.
 *
 * Callbacks of {@see onCancel()} run in the event loop and see only defaults.
 *
 * @api
 * @template T
 */
final readonly class ScopedValue
{
    /**
     * @param T $default returned by {@see get()} where no value is bound
     */
    public function __construct(
        private mixed $default = null,
    ) {}

    /**
     * @return T the bound value, or the default outside of {@see with()}
     */
    public function get(): mixed
    {
        /** @var T */
        return Context::get($this, $this->default);
    }

    /**
     * Whether a value is bound here, by an enclosing {@see with()} or inherited by the task: even a null one,
     * even one equal to the default. For code that must not override a binding made by an outer layer.
     */
    public function isBound(): bool
    {
        return Context::isBound($this);
    }

    /**
     * Runs $fn with $value bound, then restores the previous value, whatever $fn ends with.
     *
     * @template R
     * @param T $value
     * @param callable(): R $fn
     * @return R
     */
    public function with(mixed $value, callable $fn): mixed
    {
        return Context::with(
            key: $this,
            value: $value,
            fn: $fn,
        );
    }
}

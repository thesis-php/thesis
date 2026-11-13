<?php

declare(strict_types=1);

namespace Thesis\Async;

use const Thesis\Async\Internal\MAIN;

/**
 * A value per fiber, {main} included: context that has to follow a task without being passed as an argument
 * (a request id for logs, a tenant, a transaction).
 *
 * Cheaper than Revolt's FiberLocal: get() is one WeakMap lookup (Revolt's makes two, fiber → storage,
 * then instance → value, and wraps the value in an array). Nothing is cleared after event loop callbacks:
 * the owner sets and unsets the value itself. A fiber's value goes away with the fiber.
 *
 * Every task of a {@see Group} is a fiber of its own and starts without a value: nothing is inherited
 * from the fiber that spawned it. Bare event loop callbacks share one Revolt fiber, so a value set in one
 * of them is seen by the next ones: unset it before the callback returns.
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
     * @return ?T null if the current fiber has no value
     */
    public function get(): mixed
    {
        return $this->values[\Fiber::getCurrent() ?? MAIN] ?? null;
    }

    /**
     * @param T $value
     */
    public function set(mixed $value): void
    {
        $this->values[\Fiber::getCurrent() ?? MAIN] = $value;
    }

    public function unset(): void
    {
        unset($this->values[\Fiber::getCurrent() ?? MAIN]);
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Cancelled;

/**
 * Node of the cancellation tree. Every fiber always runs inside some scope.
 *
 * Only owners cancel a scope: a group (on failure or {@see GroupImpl::cancel()}), a task handle and a timeout timer.
 * That is why a caught {@see Cancelled} needs no payload: the owner tells its own cancellation
 * from a foreign one by the state of the tree.
 *
 * @internal
 */
final class Scope
{
    /**
     * Current scope of every fiber (keyed by \Fiber) that has entered one.
     *
     * Why not Revolt's FiberLocal, which does the same: {@see current()} is on the hot path, it runs in every
     * suspend(). FiberLocal::get() makes two WeakMap lookups (fiber → storage, then instance → value)
     * and wraps the value in an array; a single WeakMap plus a static for {main} is ~0.9 µs (~8%) cheaper
     * per wait. FiberLocal's automatic clearing after each event loop callback buys nothing here either:
     * {@see run()} restores the previous scope in `finally`.
     *
     * @var ?\WeakMap<object, self>
     */
    private static ?\WeakMap $fibers = null;

    /** current scope of {main} */
    private static ?self $main = null;

    private static ?self $root = null;

    private bool $cancelled = false;

    /** @var array<int, \Closure(): void> */
    private array $listeners = [];

    private int $nextListenerId = 0;

    /** @var array<int, self> held strongly to deliver cancellation; released by {@see close()} */
    private array $children = [];

    public function __construct(
        private readonly ?self $parent = null,
        private readonly bool $shield = false,
    ) {
        if ($parent !== null) {
            $parent->children[spl_object_id($this)] = $this;
        }
    }

    public static function current(): self
    {
        $fiber = \Fiber::getCurrent();
        $current = $fiber === null ? self::$main : self::$fibers[$fiber] ?? null;

        return $current ?? self::$root ??= new self();
    }

    /**
     * Runs $body in the current fiber with this scope as the current one.
     *
     * @template T
     * @param callable(): T $body
     * @return T
     */
    public function run(callable $body): mixed
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $previous = self::$main;
            self::$main = $this;

            try {
                return $body();
            } finally {
                self::$main = $previous;
            }
        }

        $fibers = self::$fibers ??= new \WeakMap();
        $previous = $fibers[$fiber] ?? null;
        $fibers[$fiber] = $this;

        try {
            return $body();
        } finally {
            if ($previous === null) {
                unset($fibers[$fiber]);
            } else {
                $fibers[$fiber] = $previous;
            }
        }
    }

    /**
     * Whether this scope or a visible ancestor is cancelled. A shield hides its ancestors.
     */
    public function isCancelled(): bool
    {
        for ($scope = $this; $scope !== null; $scope = $scope->shield ? null : $scope->parent) {
            if ($scope->cancelled) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws Cancelled
     */
    public function checkpoint(): void
    {
        if ($this->isCancelled()) {
            throw new Cancelled();
        }
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->notify();
    }

    /**
     * The listener is called at most once, synchronously inside {@see cancel()},
     * or immediately if the scope is already cancelled. It must not throw.
     *
     * @param \Closure(): void $listener
     * @return \Closure(): void unsubscribe
     */
    public function onCancel(\Closure $listener): \Closure
    {
        if ($this->isCancelled()) {
            $listener();

            return static function () {};
        }

        $id = $this->nextListenerId++;
        $this->listeners[$id] = $listener;

        return function () use ($id) {
            unset($this->listeners[$id]);
        };
    }

    /**
     * Detaches the scope from its parent. Every created scope must be closed by its owner.
     */
    public function close(): void
    {
        if ($this->parent !== null) {
            unset($this->parent->children[spl_object_id($this)]);
        }
    }

    private function notify(): void
    {
        $listeners = $this->listeners;
        $this->listeners = [];

        foreach ($listeners as $listener) {
            $listener();
        }

        foreach ($this->children as $child) {
            // A cancelled child has already notified its subtree, and nobody can subscribe to it since.
            if (!$child->shield && !$child->cancelled) {
                $child->notify();
            }
        }
    }
}

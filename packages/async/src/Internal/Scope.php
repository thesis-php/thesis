<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Cancelled;

/**
 * Node of the cancellation tree. Every fiber always runs inside some scope.
 *
 * Only owners cancel a scope: a group (on failure or {@see GroupImpl::cancelTasks()}), a task handle and a timeout timer.
 * That is why a caught {@see Cancelled} needs no payload: the owner tells its own cancellation
 * from a foreign one by the state of the tree.
 *
 * Cancellation is propagated eagerly. {@see cancel()} walks the subtree below the scope (a shield stops it)
 * once, marks every visited scope as cancelled and interrupts the waits subscribed to it;
 * a scope created later inherits the mark from its parent. So {@see isCancelled()}, which runs twice
 * in every wait, is a flag read instead of a walk up the tree.
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

    /**
     * This scope or a visible ancestor is cancelled: set by {@see notify()} and inherited on creation.
     * One flag is enough, because it never goes back: whoever cancelled the scope, its subtree has been notified.
     */
    private bool $cancelled = false;

    /** @var array<int, ContinuationImpl<*>> the waits to interrupt on cancellation, keyed by spl_object_id() */
    private array $waits = [];

    /** @var array<int, self> held strongly to deliver cancellation; released by {@see close()} */
    private array $children = [];

    public function __construct(
        private readonly ?self $parent = null,
        private readonly bool $shield = false,
    ) {
        if ($parent !== null) {
            $parent->children[spl_object_id($this)] = $this;
            // The parent's subtree has been notified before this scope existed.
            $this->cancelled = !$shield && $parent->cancelled;
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
        return $this->cancelled;
    }

    /**
     * Only checks the flag. Unlike `Thesis\Async\checkpoint()`, it does not yield to the event loop.
     *
     * @throws Cancelled
     */
    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new Cancelled();
        }
    }

    /**
     * A no-op if the scope is already cancelled, by its owner or through an ancestor: its subtree is notified then.
     */
    public function cancel(): void
    {
        if (!$this->cancelled) {
            $this->notify();
        }
    }

    /**
     * Interrupts the wait on cancellation, synchronously inside {@see cancel()},
     * or right away if the scope is already cancelled.
     *
     * The continuation itself is subscribed, not a closure around it: a wait allocates nothing for it.
     *
     * @param ContinuationImpl<*> $wait
     */
    public function subscribe(ContinuationImpl $wait): void
    {
        if ($this->cancelled) {
            $wait->interrupt();
        } else {
            // A subscribed continuation is alive, so its object id is unique among the subscribed ones.
            $this->waits[spl_object_id($wait)] = $wait;
        }
    }

    /**
     * @param ContinuationImpl<*> $wait
     */
    public function unsubscribe(ContinuationImpl $wait): void
    {
        unset($this->waits[spl_object_id($wait)]);
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
        $this->cancelled = true;
        $waits = $this->waits;
        $this->waits = [];

        foreach ($waits as $wait) {
            $wait->interrupt();
        }

        foreach ($this->children as $child) {
            // A cancelled child has already been visited, together with its subtree, and everything
            // created below it since has inherited the flag; a subscription to it is interrupted right away.
            if (!$child->shield && !$child->cancelled) {
                $child->notify();
            }
        }
    }
}

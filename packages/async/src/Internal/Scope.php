<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Thesis\Async\Cancelled;
use Thesis\Async\FiberLocal;

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
     * This scope or a visible ancestor is cancelled: set by {@see notify()} and inherited on creation.
     * One flag is enough, because it never goes back: whoever cancelled the scope, its subtree has been notified.
     */
    private bool $cancelled = false;

    /** @var array<int, ContinuationImpl<*>> the waits to interrupt on cancellation, keyed by spl_object_id() */
    private array $waits = [];

    /** @var array<int, \Closure(): void> queued on cancellation, keyed by spl_object_id(); see {@see onCancel()} */
    private array $callbacks = [];

    /** @var array<int, self> held strongly to deliver cancellation; released by {@see close()} */
    private array $children = [];

    /**
     * The nearest deadline of this scope and its visible ancestors, as {@see now()}; INF if there is none.
     * Like the cancelled flag, it is resolved on creation, so {@see remaining()} reads one field.
     */
    private readonly float $deadline;

    /**
     * @param ?float $timeout a deadline of this scope in seconds from now; an earlier one of the parent wins
     */
    public function __construct(
        private readonly ?self $parent = null,
        private readonly bool $shield = false,
        ?float $timeout = null,
    ) {
        $deadline = $timeout === null ? INF : self::now() + $timeout;

        if ($parent !== null) {
            $parent->children[spl_object_id($this)] = $this;

            // A shield hides the deadlines of its ancestors together with their cancellation:
            // the code under it would otherwise think it has no time, although nothing will stop it.
            if (!$shield) {
                // The parent's subtree has been notified before this scope existed.
                $this->cancelled = $parent->cancelled;
                $deadline = min($deadline, $parent->deadline);
            }
        }

        $this->deadline = $deadline;
    }

    public static function current(): self
    {
        /** @var self */
        return CURRENT_SCOPE->get() ?? ROOT_SCOPE;
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
        return CURRENT_SCOPE->with($this, $body);
    }

    /**
     * Whether this scope or a visible ancestor is cancelled. A shield hides its ancestors.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Seconds left until the nearest deadline of this scope or a visible ancestor, null if there is none.
     * Zero once the deadline has passed, even if its timer has not fired yet.
     */
    public function remaining(): ?float
    {
        if ($this->deadline === INF) {
            return null;
        }

        return max(0.0, $this->deadline - self::now());
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
     * Queues $callback through `EventLoop::queue()` once this scope is cancelled, by its owner or through an ancestor.
     * If it already is, $callback is queued right away. Never synchronously: user code does not run inside {@see cancel()}.
     *
     * Not a way to cancel anything: it only observes. The callback runs in the root scope.
     * It is called at most once, and never after the returned unsubscribe function is called,
     * even if it is already queued. Once the scope is closed, it is never called.
     *
     * The root scope is never cancelled, so nothing is stored for it.
     *
     * @param \Closure(): void $callback
     * @return \Closure(): void unsubscribe, idempotent
     */
    public function onCancel(\Closure $callback): \Closure
    {
        if ($this === ROOT_SCOPE) {
            return static function () {};
        }

        $pending = true;
        // Queued once at most: by notify() or right here.
        $fire = static function () use (&$pending, $callback) {
            /** @phpstan-ignore if.alwaysTrue (reset by the unsubscribe function below) */
            if ($pending) {
                $callback();
            }
        };
        $id = spl_object_id($fire);

        if ($this->cancelled) {
            EventLoop::queue($fire);
        } else {
            $this->callbacks[$id] = $fire;
        }

        // Once the scope is cancelled, $this->callbacks stays empty, and before that a stored $fire is alive,
        // so its id is not reused: unsetting it the first time removes this subscription and nothing else.
        return function () use (&$pending, $id) {
            if ($pending) {
                $pending = false;
                unset($this->callbacks[$id]);
            }
        };
    }

    /**
     * Detaches the scope from its parent. Every created scope must be closed by its owner.
     */
    public function close(): void
    {
        // A task handle may still cancel a finished task: its callbacks must not fire then.
        $this->callbacks = [];

        if ($this->parent !== null) {
            unset($this->parent->children[spl_object_id($this)]);
        }
    }

    /**
     * Monotonic, in seconds: the clock Revolt's timers run on.
     */
    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    private function notify(): void
    {
        $this->cancelled = true;
        $waits = $this->waits;
        $this->waits = [];

        foreach ($waits as $wait) {
            $wait->interrupt();
        }

        $callbacks = $this->callbacks;
        $this->callbacks = [];

        foreach ($callbacks as $callback) {
            EventLoop::queue($callback);
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

/**
 * The scope of code that runs outside of any {@see Scope::run()}. Never cancelled: it has no owner.
 *
 * @internal
 */
const ROOT_SCOPE = new Scope();

/**
 * Current scope of every fiber, {main} included, that has entered one. Read through {@see Scope::current()}.
 *
 * @internal
 * @var FiberLocal<Scope>
 */
const CURRENT_SCOPE = new FiberLocal();

<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Cancelled;
use Thesis\Async\Trace\CancelCause;

/**
 * Node of the cancellation tree. Every fiber always runs inside some scope.
 *
 * Only owners cancel a scope: a group (on failure or {@see GroupImpl::cancelTasks()}), a task handle and a timeout
 * timer. That is why a caught {@see Cancelled} needs no payload: the owner tells its own cancellation
 * from a foreign one by the state of the tree.
 *
 * Cancellation is propagated eagerly. {@see cancel()} walks the subtree below the scope (a shield stops it)
 * once, marks every visited scope as cancelled and interrupts the waits subscribed to it;
 * a scope created later inherits the mark from its parent. So {@see isCancelled()}, which runs twice
 * in every wait, is a flag read instead of a walk up the tree.
 *
 * @internal
 */
final class Scope implements Waiter
{
    /**
     * The longest `Time\Duration` in nanoseconds: a little less than PHP_INT_MAX, the saturated deadline.
     */
    public const int LONGEST_DURATION = 9_223_372_035 * 1_000_000_000 + 999_999_999;

    /**
     * The clock of {@see self::now()} while `Thesis\Async\virtualTime()` runs, bound by it.
     * Here, not found through the event loop driver, which may be decorated;
     * and here, not on {@see VirtualClock}, so that the class is loaded only if `virtualTime()` is used.
     */
    public static ?VirtualClock $virtualClock = null;

    /**
     * Bound while `Thesis\Async\Trace\observe()` runs. Every hook is `Scope::$tracer?->...`: without an observation,
     * one null check that does not evaluate the arguments. Here for the same reason as {@see $virtualClock}.
     */
    public static ?Tracer $tracer = null;

    /**
     * This scope or a visible ancestor is cancelled: set by {@see notify()} and inherited on creation.
     * One flag is enough, because it never goes back: whoever cancelled the scope, its subtree has been notified.
     */
    public private(set) bool $isCancelled = false;

    /**
     * @var array<int, ContinuationImpl<*>> the waits to interrupt on cancellation, keyed by spl_object_id()
     */
    private array $waits = [];

    /**
     * @var array<int, \Closure(): void> the {@see onCancel()} callbacks not called yet, keyed by spl_object_id():
     *                                  queued on cancellation, and called by {@see endCallbacks()} if still pending
     */
    private array $callbacks = [];

    /**
     * Set by {@see endCallbacks()}: once the code of the scope has ended, it stores no callbacks.
     */
    private bool $callbacksEnded = false;

    /**
     * @var array<int, self> held strongly to deliver cancellation; released by {@see close()}
     */
    private array $children = [];

    /**
     * The nearest deadline of this scope and its visible ancestors, as {@see self::now()}; null if there is none.
     * Like the cancelled flag, it is resolved on creation, so {@see remaining()} reads one field.
     */
    private readonly ?int $deadline;

    /**
     * @param ?int $timeout a deadline of this scope in nanoseconds from now; an earlier one of the parent wins
     */
    public function __construct(
        private readonly ?self $parent = null,
        private readonly bool $shield = false,
        ?int $timeout = null,
    ) {
        $deadline = $timeout === null ? null : self::after($timeout);

        if ($parent !== null) {
            $parent->children[spl_object_id($this)] = $this;

            // A shield hides the deadlines of its ancestors together with their cancellation:
            // the code under it would otherwise think it has no time, although nothing will stop it.
            if (!$shield) {
                // The parent's subtree has been notified before this scope existed.
                $this->isCancelled = $parent->isCancelled;

                if ($parent->deadline !== null) {
                    $deadline = $deadline === null ? $parent->deadline : min($deadline, $parent->deadline);
                }
            }
        }

        $this->deadline = $deadline;
    }

    public static function current(): self
    {
        /** @var self */
        return CURRENT_SCOPE[fiberKey()] ?? ROOT_SCOPE;
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
        // A constant cannot be written to by offset, its object can.
        $scopes = CURRENT_SCOPE;
        /** @var ?self $previous */
        $previous = $scopes[fiberKey()] ?? null;
        $scopes[fiberKey()] = $this;

        try {
            return $body();
        } finally {
            // fiberKey() again, not kept in a variable while $body may be suspended: see fiberKey().
            if ($previous === null) {
                unset($scopes[fiberKey()]);
            } else {
                $scopes[fiberKey()] = $previous;
            }
        }
    }

    /**
     * Nanoseconds left until the nearest deadline of this scope or a visible ancestor, null if there is none.
     * Zero once the deadline has passed, even if its timer has not fired yet.
     *
     * @return ?non-negative-int
     */
    public function remaining(): ?int
    {
        if ($this->deadline === null) {
            return null;
        }

        // A saturated deadline is further than the longest Duration while the clock is below 0.86 s.
        return min(max(0, $this->deadline - self::now()), self::LONGEST_DURATION);
    }

    /**
     * Only checks the flag. Unlike `Thesis\Async\checkpoint()`, it does not yield to the event loop.
     *
     * @throws Cancelled
     */
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled) {
            throw Cancelled::create();
        }
    }

    /**
     * A no-op if the scope is already cancelled, by its owner or through an ancestor: its subtree is notified then.
     *
     * @param ?self $by the scope that cancels it, for the trace: null for none
     * @param ?\Throwable $error the failure that cancels it, for the trace: only for {@see CancelCause::Failure}
     */
    public function cancel(CancelCause $cause, ?self $by, ?\Throwable $error = null): void
    {
        if (!$this->isCancelled) {
            $this->notify();
            self::$tracer?->cancelled($this, $cause, $by, $error);
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
        if ($this->isCancelled) {
            $wait->interrupt();
        } elseif ($this !== ROOT_SCOPE) {
            // The root scope is never cancelled: holding its waits would only keep abandoned fibers alive.
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
     * Queues $callback to the microtasks of {@see Loop} once this scope is cancelled, by its owner or through an ancestor.
     * If it already is, $callback is queued right away. Never synchronously: user code does not run inside
     * {@see cancel()}. If the queue has not reached it when the code of the scope ends, {@see endCallbacks()} calls it:
     * a callback of a cancelled scope is always called before the scope ends, and never after.
     *
     * Not a way to cancel anything: it only observes. The callback runs in the root scope, with no scoped values.
     * It is called at most once, and never after the returned unsubscribe function is called,
     * even if it is already queued. Once the code of the scope has ended, it is never called.
     *
     * The root scope is never cancelled and an ended one never calls back, so nothing is stored for them.
     *
     * @param \Closure(): void $callback
     * @return \Closure(): void unsubscribe, idempotent
     */
    public function onCancel(\Closure $callback): \Closure
    {
        if ($this === ROOT_SCOPE || $this->callbacksEnded) {
            return static function () {};
        }

        $pending = true;
        // Never read: $fire runs only after $id is its own id. Only a capture by reference needs it defined.
        // @infection-ignore DecrementInteger, IncrementInteger
        $id = 0;
        // Queued once at most, by notify() or right here, and maybe called
        // earlier by endCallbacks(): only the first call counts.
        $fire = function () use (&$pending, &$id, $callback) {
            if ($pending) {
                $pending = false;
                unset($this->callbacks[$id]);
                $callback();
            }
        };
        $id = spl_object_id($fire);
        $this->callbacks[$id] = $fire;

        if ($this->isCancelled) {
            Loop::get()->queue($fire);
        }

        // A stored $fire is alive, so its id is not reused while it is stored, and it is removed once it is called:
        // unsetting it while still pending removes this subscription and nothing else.
        return function () use (&$pending, $id) {
            if ($pending) {
                $pending = false;
                unset($this->callbacks[$id]);
            }
        };
    }

    /**
     * Detaches the scope from its parent and {@see endCallbacks()}. Every created scope must be closed by its owner.
     */
    public function close(): void
    {
        // Never throws: a callback that throws goes to the event loop.
        $this->endCallbacks();

        if ($this->parent !== null) {
            unset($this->parent->children[spl_object_id($this)]);
        }
    }

    /**
     * The code of the scope has ended, the scope itself may still be needed to cancel its children: a group body
     * that has failed or returned while its tasks run. A failure cancels the body scope then, and a callback
     * of the body must not run after the body.
     *
     * If the scope is cancelled, the {@see onCancel()} callbacks the queue has not reached yet are called here,
     * so that none runs after the code has ended; otherwise they are dropped. Either way, none is called afterwards:
     * a task handle may still cancel a finished task.
     */
    public function endCallbacks(): void
    {
        $this->callbacksEnded = true;
        $callbacks = $this->callbacks;
        $this->callbacks = [];

        if ($this->isCancelled && $callbacks !== []) {
            // As from the queue: in the root scope, with no scoped values.
            ROOT_SCOPE->run(static fn() => Context::run([], static function () use ($callbacks) {
                foreach ($callbacks as $fire) {
                    try {
                        $fire();
                    } catch (\Throwable $error) {
                        // As if it had been thrown from the queue: to the event loop,
                        // not to whoever ends the scope.
                        Loop::get()->queue(static function () use ($error) {
                            throw $error;
                        });
                    }
                }
            }));
        }
    }

    /**
     * The timer of the deadline of `Thesis\Async\timeout()`, the scope itself: no closure per timeout.
     */
    #[\Override]
    public function wake(): void
    {
        $this->cancel(CancelCause::Deadline, by: $this);
    }

    /**
     * Monotonic, in nanoseconds: the clock the timers of {@see Loop} run on. The only clock of the library:
     * `Thesis\Async\Instant` too. Within `Thesis\Async\virtualTime()`, {@see $virtualClock} instead.
     * Not on the hot path of a wait: only a deadline, {@see remaining()} and the rate limiters read the clock.
     */
    public static function now(): int
    {
        // @phpstan-ignore return.type (hrtime() returns a float only on 32-bit PHP, which no package supports)
        return self::$virtualClock->now ?? hrtime(true);
    }

    /**
     * {@see now()} plus $nanoseconds, saturated: a deadline centuries away must not overflow into a float.
     */
    public static function after(int $nanoseconds): int
    {
        $deadline = self::now() + $nanoseconds;

        // @phpstan-ignore function.alreadyNarrowedType (an int overflow turns the sum into a float)
        return \is_int($deadline) ? $deadline : PHP_INT_MAX;
    }

    private function notify(): void
    {
        $this->isCancelled = true;
        $waits = $this->waits;
        $this->waits = [];

        foreach ($waits as $wait) {
            $wait->interrupt();
        }

        // They stay stored until called: endCallbacks() calls those the queue has not reached yet.
        if ($this->callbacks !== []) {
            $loop = Loop::get();

            foreach ($this->callbacks as $fire) {
                $loop->queue($fire);
            }
        }

        foreach ($this->children as $child) {
            // A cancelled child has already been visited, together with its subtree, and everything
            // created below it since has inherited the flag; a subscription to it is interrupted right away.
            if (!$child->shield && !$child->isCancelled) {
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
 * Current scope of every fiber that has entered one, keyed by {@see fiberKey()}. Bound by {@see Scope::run()},
 * which restores the previous one, so nothing leaks into the next code that runs in the fiber.
 *
 * Read in every wait through {@see Scope::current()}, so it is a single WeakMap lookup. Revolt's FiberLocal
 * makes two (fiber → storage, then instance → value) and wraps the value in an array: ~0.9 µs (~8%) more per wait.
 *
 * @internal
 * @var \WeakMap<object, Scope>
 */
const CURRENT_SCOPE = new \WeakMap();

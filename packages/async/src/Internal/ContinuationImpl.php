<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Cancelled;
use Thesis\Async\Continuation;
use Thesis\Async\Deadlock;

/**
 * @internal
 * @template T
 * @implements Continuation<T>
 */
final class ContinuationImpl implements Continuation, Waiter
{
    private const int PENDING = 0;
    private const int PARKED = 1;
    private const int FIRED = 2;

    private int $state = self::PENDING;

    /**
     * @var ?T
     */
    private mixed $value = null;

    private ?\Throwable $error = null;

    private bool $interrupted = false;

    /**
     * The fiber parked here, and the loop that resumes it.
     *
     * @var ?\Fiber<mixed, mixed, mixed, mixed>
     */
    private ?\Fiber $fiber = null;

    private ?Loop $loop = null;

    public function resume(mixed $value = null): bool
    {
        return $this->fire(value: $value, error: null, interrupted: false);
    }

    public function throw(\Throwable $error): bool
    {
        // Cancellation of a wait is the scope's business: it goes through interrupt().
        if ($error instanceof Cancelled) {
            throw new \ValueError(
                message: 'A continuation cannot be thrown Cancelled: the waiting scope is not cancelled. Throw a regular exception.',
            );
        }

        return $this->fire(value: null, error: $error, interrupted: false);
    }

    /**
     * The event of a timer or a stream of {@see Loop}: a wait that it ends returns true.
     */
    #[\Override]
    public function wake(): void
    {
        /** @var T */
        $ready = true;
        $this->resume($ready);
    }

    /**
     * Fires the continuation with an error that {@see Loop} throws into the wait, a {@see Deadlock}
     * among them: unlike {@see throw()}, it takes any.
     */
    public function fail(\Throwable $error): bool
    {
        return $this->fire(value: null, error: $error, interrupted: false);
    }

    /**
     * Fires the continuation on behalf of cancellation: {@see park()} then throws a {@see Cancelled}
     * created in the parked fiber, so its trace points at the interrupted wait.
     */
    public function interrupt(): bool
    {
        return $this->fire(value: null, error: null, interrupted: true);
    }

    /**
     * Uncancellable wait. Use `Thesis\Async\suspend()` for a cancellable one.
     *
     * The fiber is suspended, and {@see Loop} resumes the fiber that has returned to it once the continuation
     * fires: that one may be an outer fiber, which runs this one inside and passes the suspensions through
     * (Testo runs a test so). The continuation is left in {@see Loop::$parked}, not passed as the value of
     * `Fiber::suspend()`: a fiber suspended with an object is never collected in a cycle (`agents/fibers.md`).
     * Outside a run of the loop, and where the loop runs (in {main}, or in the fiber of an outer loop),
     * nothing could resume it, so a wait there is refused.
     *
     * @return T
     * @throws \LogicException outside a run of the loop: only code in its fibers can wait
     */
    public function park(): mixed
    {
        if ($this->state === self::PARKED) {
            throw new \LogicException('Continuation is already parked.');
        }

        if ($this->state === self::PENDING) {
            $loop = Loop::get();
            $fiber = \Fiber::getCurrent();

            if ($loop->main === null || $fiber === $loop->host) {
                throw new \LogicException('A wait outside the event loop cannot be resumed: wrap the code in group().');
            }

            $this->state = self::PARKED;
            Loop::$parked = $this;
            \Fiber::suspend();
        }

        if ($this->interrupted) {
            throw Cancelled::create();
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        /** @var T */
        return $this->value;
    }

    /**
     * {@see Loop} has found the continuation parked by $fiber, the fiber it has resumed: it resumes the same one.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public function suspended(Loop $loop, \Fiber $fiber): void
    {
        if ($this->state === self::PARKED) {
            $this->fiber = $fiber;
            $this->loop = $loop;
        } else {
            // Fired by the code that has passed the suspension through.
            $loop->resume($fiber);
        }
    }

    /**
     * @param ?T $value
     */
    private function fire(mixed $value, ?\Throwable $error, bool $interrupted): bool
    {
        if ($this->state === self::FIRED) {
            return false;
        }

        $fiber = $this->fiber;
        $loop = $this->loop;
        $this->state = self::FIRED;
        $this->fiber = null;
        $this->loop = null;
        $this->value = $value;
        $this->error = $error;
        $this->interrupted = $interrupted;

        if ($fiber !== null) {
            $loop?->resume($fiber);
        }

        return true;
    }
}

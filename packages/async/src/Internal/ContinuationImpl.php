<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
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
     * @var ?Suspension<null>
     */
    private ?Suspension $suspension = null;

    /**
     * The fiber parked on {@see Loop::$current}, and the loop that resumes it.
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
     * Known gap, won't fix: in {main}, Revolt resumes the suspension from a deferred callback, and if a foreign
     * callback throws first, it throws UncaughtThrowable out of the suspension instead. A {@see resume()} that has
     * returned true is lost then, although its caller considers the value handed over (a channel value,
     * a semaphore permit). Keeping it would mean rethrowing Revolt's error elsewhere, which is not local.
     *
     * @return T
     */
    public function park(): mixed
    {
        if ($this->state === self::PARKED) {
            throw new \LogicException('Continuation is already parked.');
        }

        $loop = Loop::$current;

        if ($this->state === self::PENDING && $loop !== null) {
            $fiber = \Fiber::getCurrent()
                ?? throw new \LogicException('A wait in {main} cannot be resumed: wrap the code in group().');
            $this->state = self::PARKED;
            $this->fiber = $fiber;
            $this->loop = $loop;

            if ($fiber === $loop->main) {
                $loop->mainWait = $this;
            }

            \Fiber::suspend();
        } elseif ($this->state === self::PENDING) {
            $this->state = self::PARKED;
            /** @var Suspension<null> */
            $suspension = EventLoop::getSuspension();
            $this->suspension = $suspension;
            $suspension->suspend();
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
     * @param ?T $value
     */
    private function fire(mixed $value, ?\Throwable $error, bool $interrupted): bool
    {
        if ($this->state === self::FIRED) {
            return false;
        }

        $suspension = $this->suspension;
        $fiber = $this->fiber;
        $loop = $this->loop;
        $this->state = self::FIRED;
        $this->suspension = null;
        $this->fiber = null;
        $this->loop = null;
        $this->value = $value;
        $this->error = $error;
        $this->interrupted = $interrupted;
        $suspension?->resume();

        if ($fiber !== null) {
            $loop?->resume($fiber);
        }

        return true;
    }
}

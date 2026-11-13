<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Thesis\Async\Cancelled;
use Thesis\Async\Continuation;

/**
 * @internal
 * @template T
 * @implements Continuation<T>
 */
final class ContinuationImpl implements Continuation
{
    private const int PENDING = 0;
    private const int PARKED = 1;
    private const int FIRED = 2;

    private int $state = self::PENDING;

    /** @var ?T */
    private mixed $value = null;

    private ?\Throwable $error = null;

    private bool $interrupted = false;

    /** @var ?Suspension<null> */
    private ?Suspension $suspension = null;

    public function resume(mixed $value = null): bool
    {
        return $this->fire($value, null, false);
    }

    public function throw(\Throwable $error): bool
    {
        // Cancellation of a wait is the scope's business: it goes through interrupt().
        if ($error instanceof Cancelled) {
            throw new \ValueError(
                'A continuation cannot be thrown Cancelled: the waiting scope is not cancelled. Throw a regular exception.',
            );
        }

        return $this->fire(null, $error, false);
    }

    /**
     * Fires the continuation on behalf of cancellation: {@see park()} then throws a {@see Cancelled}
     * created in the parked fiber, so its trace points at the interrupted wait.
     */
    public function interrupt(): bool
    {
        return $this->fire(null, null, true);
    }

    /**
     * Uncancellable wait. Use `Thesis\Async\suspend()` for a cancellable one.
     *
     * @return T
     */
    public function park(): mixed
    {
        if ($this->state === self::PARKED) {
            throw new \LogicException('Continuation is already parked.');
        }

        if ($this->state === self::PENDING) {
            $this->state = self::PARKED;
            /** @var Suspension<null> */
            $suspension = EventLoop::getSuspension();
            $this->suspension = $suspension;
            $suspension->suspend();
        }

        if ($this->interrupted) {
            throw new Cancelled();
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
        $this->state = self::FIRED;
        $this->suspension = null;
        $this->value = $value;
        $this->error = $error;
        $this->interrupted = $interrupted;
        $suspension?->resume();

        return true;
    }
}

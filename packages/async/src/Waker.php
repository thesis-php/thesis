<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * One-shot handle that wakes exactly one wait.
 *
 * Unlike {@see Suspension}, which is shared by all waits of a fiber, a waker fires once:
 * late {@see resume()} and {@see throw()} calls are ignored instead of waking an unrelated wait.
 * It may also fire before the fiber is parked, in which case {@see park()} returns immediately.
 *
 * @api
 * @template T
 */
final class Waker
{
    private const int ARMED = 0;
    private const int PARKED = 1;
    private const int FIRED = 2;

    private int $state = self::ARMED;

    /** @var ?T */
    private mixed $value = null;

    private ?\Throwable $error = null;

    /** @var ?Suspension<T> */
    private ?Suspension $suspension = null;

    /**
     * @param T $value
     * @return bool whether this call won, false if the waker has already fired
     */
    public function resume(mixed $value = null): bool
    {
        if ($this->state === self::FIRED) {
            return false;
        }

        $suspension = $this->suspension;
        $this->state = self::FIRED;
        $this->suspension = null;

        if ($suspension === null) {
            $this->value = $value;
        } else {
            $suspension->resume($value);
        }

        return true;
    }

    /**
     * @return bool whether this call won, false if the waker has already fired
     */
    public function throw(\Throwable $error): bool
    {
        if ($this->state === self::FIRED) {
            return false;
        }

        $suspension = $this->suspension;
        $this->state = self::FIRED;
        $this->suspension = null;

        if ($suspension === null) {
            $this->error = $error;
        } else {
            $suspension->throw($error);
        }

        return true;
    }

    /**
     * Uncancellable wait. Use {@see suspend()} for a cancellable one.
     *
     * @internal
     * @return T
     */
    public function park(): mixed
    {
        if ($this->state === self::PARKED) {
            throw new \LogicException('Waker is already parked.');
        }

        if ($this->state === self::FIRED) {
            if ($this->error !== null) {
                throw $this->error;
            }

            /** @var T */
            return $this->value;
        }

        /** @var Suspension<T> */
        $suspension = EventLoop::getSuspension();
        $this->state = self::PARKED;
        $this->suspension = $suspension;

        return $suspension->suspend();
    }
}

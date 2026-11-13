<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Thesis\Async\Waker;

/**
 * @internal
 * @template T
 * @implements Waker<T>
 */
final class WakerImpl implements Waker
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
     * Uncancellable wait. Use `Thesis\Async\suspend()` for a cancellable one.
     *
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

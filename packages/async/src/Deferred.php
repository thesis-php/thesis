<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * A value that will be known later: one side completes it, any number of fibers await it.
 *
 * It is one-shot, like {@see Continuation}, but not tied to a single wait: every {@see await()},
 * before or after completion, gets the same value or error. Late {@see complete()} and {@see fail()} calls
 * are ignored and return false.
 *
 * {@see await()} goes through {@see suspend()}, so it is cancellable. Cancelling an awaiter does not affect
 * the deferred or the other awaiters.
 *
 * @api
 * @template T
 */
final class Deferred
{
    public private(set) bool $isDone = false;

    /**
     * @var ?T
     */
    private mixed $value = null;

    private ?\Throwable $error = null;

    /**
     * @var array<int, Continuation<null>>
     */
    private array $waiters = [];

    /**
     * @param T $value
     * @return bool whether this call won, false if the deferred is already done
     */
    public function complete(mixed $value = null): bool
    {
        return $this->settle($value, null);
    }

    /**
     * @return bool whether this call won, false if the deferred is already done
     * @throws \ValueError if $error is {@see Cancelled}: it would be thrown in awaiters whose scope is not cancelled
     */
    public function fail(\Throwable $error): bool
    {
        if ($error instanceof Cancelled) {
            throw new \ValueError(
                message: 'A deferred cannot fail with Cancelled: its awaiters are not cancelled. Fail it with a regular exception.',
            );
        }

        return $this->settle(null, $error);
    }

    /**
     * Does not suspend if the deferred is already done: its value is returned even in a cancelled scope,
     * so it is not a cancellation point then, and it does not yield to the event loop.
     *
     * @return T
     * @throws \Throwable the error passed to {@see fail()}
     */
    public function await(): mixed
    {
        if (!$this->isDone) {
            suspend(function (Continuation $continuation) {
                /** @var Continuation<null> $continuation */
                $id = spl_object_id($continuation);
                $this->waiters[$id] = $continuation;

                return function () use ($id) {
                    unset($this->waiters[$id]);
                };
            });
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
    private function settle(mixed $value, ?\Throwable $error): bool
    {
        if ($this->isDone) {
            return false;
        }

        $this->isDone = true;
        $this->value = $value;
        $this->error = $error;

        $waiters = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            $waiter->resume();
        }

        return true;
    }
}

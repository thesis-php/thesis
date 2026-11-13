<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * A held {@see Semaphore} permit, obtained from {@see Semaphore::acquire()}.
 *
 * It belongs to whoever holds the object, not to a fiber: it may be stored, passed around
 * and released by another fiber.
 *
 * It is one-shot: the first {@see release()} wins, later calls do nothing and return false,
 * so a permit can never be returned twice. An implementation of {@see Semaphore} only supplies the callable
 * that returns the permit; these guarantees are enforced here, once for all of them.
 *
 * @api
 */
final class Lock
{
    public private(set) bool $isReleased = false;

    /** @var callable(): void */
    private readonly mixed $release;

    /**
     * @param callable(): void $release returns the permit. Called at most once, inside {@see shield()}:
     *     release usually happens in `finally` of a cancelled scope, and a release that has to wait
     *     (a distributed lock) must still be able to. Such a callable must bound its own wait with {@see timeout()}.
     */
    public function __construct(callable $release)
    {
        $this->release = $release;
    }

    /**
     * If the lock is destroyed unreleased, the permit is returned here, so that nobody waits for it forever.
     * Do not rely on it: when an object is destroyed is up to the engine (a reference cycle, an exception trace
     * holding the lock, ...). But a lock captured by a task that was cancelled before it started is dropped
     * exactly like this, and that is not a bug.
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * Returns the permit. Works in a cancelled scope.
     *
     * @return bool whether this call released the lock, false if it was already released
     */
    public function release(): bool
    {
        if ($this->isReleased) {
            return false;
        }

        // Before the call: if the release fails, a retry must not return the permit twice.
        $this->isReleased = true;
        shield($this->release);

        return true;
    }
}

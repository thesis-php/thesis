<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * In-process {@see KeyedSemaphore}: a {@see LocalSemaphore} per key, fair in the same way within a key.
 *
 * A key takes memory only while it is in use: its semaphore is created by the first acquire()
 * and dropped once nobody holds or waits for it, so keys that come from outside (user ids, paths) do not pile up.
 *
 * @api
 */
final class LocalKeyedSemaphore implements KeyedSemaphore
{
    /** @var array<array-key, LocalSemaphore> keys in use */
    private array $semaphores = [];

    /** @var array<array-key, positive-int> holders and waiters per key in use */
    private array $users = [];

    /**
     * @param positive-int $permits per key
     */
    public function __construct(
        private readonly int $permits,
    ) {}

    public function acquire(string $key): Lock
    {
        $semaphore = $this->semaphores[$key] ??= new LocalSemaphore($this->permits);
        $this->users[$key] = ($this->users[$key] ?? 0) + 1;

        try {
            $lock = $semaphore->acquire();
        } catch (\Throwable $exception) {
            $this->leave($key);

            throw $exception;
        }

        return new Lock(function () use ($lock, $key) {
            // First the permit: it is handed over to a waiter, who keeps the key in use.
            $lock->release();
            $this->leave($key);
        });
    }

    private function leave(string $key): void
    {
        /** @phpstan-ignore offsetAccess.notFound (the key is in use: acquire() has counted the caller) */
        $users = $this->users[$key] - 1;

        if ($users > 0) {
            $this->users[$key] = $users;

            return;
        }

        // Nobody holds or waits: the semaphore has all its permits and may be created anew.
        unset($this->users[$key], $this->semaphores[$key]);
    }
}

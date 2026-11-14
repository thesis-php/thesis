<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * In-process {@see KeyedMutex}: a {@see LocalKeyedSemaphore} with one permit per key.
 *
 * @api
 */
final readonly class LocalKeyedMutex implements KeyedMutex
{
    private LocalKeyedSemaphore $semaphore;

    public function __construct()
    {
        $this->semaphore = new LocalKeyedSemaphore(1);
    }

    public function acquire(string $key): Lock
    {
        return $this->semaphore->acquire($key);
    }
}

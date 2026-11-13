<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * In-process {@see Mutex}: a {@see LocalSemaphore} with one permit, fair in the same way.
 *
 * @api
 */
final readonly class LocalMutex implements Mutex
{
    private LocalSemaphore $semaphore;

    public function __construct()
    {
        $this->semaphore = new LocalSemaphore(1);
    }

    public function acquire(): Lock
    {
        return $this->semaphore->acquire();
    }
}

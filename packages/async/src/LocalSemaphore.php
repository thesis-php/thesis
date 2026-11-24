<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\WaitQueue;

/**
 * In-process {@see Semaphore}: `new LocalSemaphore(1)` is a mutex. Fair:
 * permits go to the waiters in the order they came.
 *
 * A released permit is handed over to the first waiter directly instead of being returned to the pool,
 * so a fiber that calls acquire() at the right moment cannot overtake the ones already waiting.
 * A waiter that has been cancelled in the meantime refuses the permit ({@see Continuation::resume()}
 * returns false), and it goes to the next one. A waiter cancelled after the handover keeps the permit:
 * acquire() returns the lock, and the next wait of the holder throws {@see Cancelled}.
 *
 * acquire() of a free permit returns immediately, without a wait and so without a cancellation check,
 * like {@see Deferred::await()} of a done deferred.
 *
 * @api
 */
final class LocalSemaphore implements Semaphore
{
    /**
     * @var non-negative-int
     */
    private int $permits;

    /**
     * @var ?WaitQueue<Continuation<null>>
     */
    private ?WaitQueue $waiters = null;

    /**
     * @param positive-int $permits
     */
    public function __construct(int $permits)
    {
        $this->permits = $permits;
    }

    public function acquire(): Lock
    {
        // A free permit means nobody waits: release() returns a permit only when there is no one to hand it to.
        if ($this->permits > 0) {
            --$this->permits;
        } else {
            suspend(function (Continuation $continuation) {
                /** @var Continuation<null> $continuation */
                $waiters = $this->waiters ??= new WaitQueue();
                $key = $waiters->push($continuation);

                return static function () use ($waiters, $key) {
                    $waiters->remove($key);
                };
            });
            // Resumed by release(): the permit has been handed over.
        }

        return new Lock($this->release(...));
    }

    private function release(): void
    {
        if ($this->waiters !== null) {
            while (($waiter = $this->waiters->shift()) !== null) {
                if ($waiter->resume()) {
                    return;
                }
            }

            $this->waiters = null;
        }

        ++$this->permits;
    }
}

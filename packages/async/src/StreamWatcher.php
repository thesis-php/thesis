<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\ContinuationImpl;
use Thesis\Async\Internal\Loop;
use Thesis\Async\Internal\Scope;
use Thesis\Async\Internal\Timer;
use Time\Duration;
use function Thesis\Time\toNs;

/**
 * The waits on one stream, owned by whoever owns the stream, so that closing it can end them.
 *
 * Every wait is a wait of {@see suspend()}, without its closures: it is cancelled by the task tree and leaves
 * nothing in the event loop behind. Call {@see close()} before closing the stream: the kernel forgets a closed stream, and a fiber
 * waiting for it would never wake up. close() ends every wait with false, and every later one
 * returns false at once, so the fibers waiting for the stream learn that it is gone.
 *
 * Any number of fibers may wait at once, for the same readiness too: each of them is woken.
 *
 * @api
 */
final class StreamWatcher
{
    public private(set) bool $isClosed = false;

    /**
     * @var array<int, array{ContinuationImpl<bool>, Loop, bool|Timer}> each wait, the loop it is registered in,
     *     and for what: a write (true), a read (false) or a timer, by spl_object_id() of the continuation
     */
    private array $waits = [];

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream,
    ) {}

    /**
     * Waits until a read would not block: there are bytes, the end of the stream, or an error.
     * Read before waiting: TLS keeps decrypted bytes where the event loop does not see them.
     *
     * @return bool true once readable, false if {@see close()} has ended the wait or was called before
     */
    public function readable(): bool
    {
        return $this->wait(false);
    }

    /**
     * Waits until a write would not block: the buffer of the OS has room, or the connection has failed.
     *
     * @return bool true once writable, false if {@see close()} has ended the wait or was called before
     */
    public function writable(): bool
    {
        return $this->wait(true);
    }

    /**
     * This method waits like `delay()` and {@see close()} can end the wait: a backoff of the stream's owner
     * between attempts, which must not outlive the stream.
     *
     * @return bool true once the time has passed, false if {@see close()} has ended the wait or was called before
     */
    public function delay(Duration $duration): bool
    {
        return $this->wait(toNs($duration));
    }

    /**
     * Ends every wait with false and forgets it in the event loop; every later wait returns false at once.
     * Call it before closing the stream. Idempotent.
     */
    public function close(): void
    {
        $this->isClosed = true;
        $waits = $this->waits;
        $this->waits = [];

        foreach ($waits as $wait) {
            $this->unregister($wait);
            $wait[0]->resume(false);
        }
    }

    /**
     * The steps of `suspend()` without its closures, a fifth of a wait: the check of cancellation,
     * the registration, the subscription, the park, and the unregistration whatever ends it.
     *
     * @param bool|int $for a write (true), a read (false) or a sleep of so many nanoseconds
     */
    private function wait(bool|int $for): bool
    {
        // Like a receive() from a closed channel: no wait, so no cancellation check either.
        if ($this->isClosed) {
            return false;
        }

        $scope = Scope::current();
        $scope->throwIfCancelled();

        /** @var ContinuationImpl<bool> */
        $continuation = new ContinuationImpl();
        $loop = Loop::get();

        if (\is_int($for)) {
            $registered = $loop->delay($for, $continuation);
        } else {
            $registered = $for;
            $loop->watch($this->stream, $continuation, write: $for);
        }

        $id = spl_object_id($continuation);
        $this->waits[$id] = [$continuation, $loop, $registered];
        $scope->subscribe($continuation);
        Scope::$tracer?->suspended($scope);
        $error = null;

        try {
            return $continuation->park();
        } catch (\Throwable $error) {
            throw $error;
        } finally {
            $scope->unsubscribe($continuation);
            $this->forget($id);
            Scope::$tracer?->resumed($scope, $error);
        }
    }

    /**
     * This method does nothing if {@see close()} has already ended the wait.
     */
    private function forget(int $id): void
    {
        $wait = $this->waits[$id] ?? null;

        if ($wait !== null) {
            unset($this->waits[$id]);
            $this->unregister($wait);
        }
    }

    /**
     * @param array{ContinuationImpl<bool>, Loop, bool|Timer} $wait
     */
    private function unregister(array $wait): void
    {
        [$continuation, $loop, $registered] = $wait;

        if ($registered instanceof Timer) {
            $loop->cancel($registered);
        } else {
            $loop->unwatch($this->stream, $continuation, write: $registered);
        }
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Async;

use Io\Poll\Event;
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
     * @var array<int, array{ContinuationImpl<bool>, Loop, Event::Read|Event::Write|Event::ReadHangUp|Timer}>
     *     Each continuation, its loop and its event or timer, keyed by spl_object_id()
     */
    private array $waits = [];

    private ?ReadObserver $observer = null;

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
        return $this->wait(Event::Read);
    }

    /**
     * Waits for a peer write shutdown on epoll, or readability on other backends.
     * A subsequent read distinguishes buffered bytes, EOF and transport errors. Local close returns false.
     */
    public function readHangUp(): bool
    {
        return !$this->isClosed && $this->wait(Loop::get()->supportsReadHangUp() ? Event::ReadHangUp : Event::Read);
    }

    /**
     * Replace the stream's observer. Its callback runs between fibers and must not suspend.
     * Return true to wait for another event, or false to pause. Closing the watcher removes the observer.
     *
     * @param \Closure(ReadObserver): bool $probe
     * @param bool $hangUpOnly wait for peer write shutdown on epoll, otherwise use read readiness
     * @throws \LogicException if the watcher is closed
     */
    public function observeReadable(\Closure $probe, bool $hangUpOnly = false): ReadObserver
    {
        if ($this->isClosed) {
            throw new \LogicException('Cannot observe a closed stream.');
        }

        $this->observer?->close();
        $loop = Loop::get();
        $event = $hangUpOnly && $loop->supportsReadHangUp() ? Event::ReadHangUp : Event::Read;

        return $this->observer = new ReadObserver($this->stream, $loop, $event, $probe);
    }

    /**
     * Waits until a write would not block: the buffer of the OS has room, or the connection has failed.
     *
     * @return bool true once writable, false if {@see close()} has ended the wait or was called before
     */
    public function writable(): bool
    {
        return $this->wait(Event::Write);
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
        $this->observer?->close();
        $this->observer = null;
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
     * @param Event::Read|Event::Write|Event::ReadHangUp|int $for the stream event or the nanoseconds to wait
     */
    private function wait(Event|int $for): bool
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
            $loop->watch($this->stream, $continuation, $for);
        }

        $id = spl_object_id($continuation);
        $this->waits[$id] = [$continuation, $loop, $registered];
        $scope->subscribe($continuation);
        Scope::$tracer?->suspended($scope);
        $error = null;

        try {
            $ready = $continuation->park();
            // The loop removes a wait before waking it, and close() removes it before resuming it.
            unset($this->waits[$id]);

            return $ready;
        } catch (\Throwable $error) {
            throw $error;
        } finally {
            $scope->unsubscribe($continuation);
            if (isset($this->waits[$id])) {
                $this->unregister($this->waits[$id]);
                unset($this->waits[$id]);
            }
            Scope::$tracer?->resumed($scope, $error);
        }
    }

    /**
     * @param array{ContinuationImpl<bool>, Loop, Event::Read|Event::Write|Event::ReadHangUp|Timer} $wait
     */
    private function unregister(array $wait): void
    {
        [$continuation, $loop, $registered] = $wait;

        if ($registered instanceof Timer) {
            $loop->cancel($registered);
        } else {
            $loop->unwatch($this->stream, $continuation, $registered);
        }
    }
}

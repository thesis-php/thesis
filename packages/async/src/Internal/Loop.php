<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollWaitException;
use Io\Poll\InvalidHandleException;
use Thesis\Async\Deadlock;
use Thesis\Async\Pacer;
use Thesis\SourceLocation\Location;
use function Thesis\Time\ns;

/**
 * The scheduler of fibers on `Io\Poll`. It runs only in {main}, and the code it runs only in fibers.
 *
 * It knows five things: the ready fibers, the timers, the streams, the signals and the microtasks. A wait hands
 * it a {@see Waiter}, which it wakes once the event has come; a woken {@see ContinuationImpl} puts its fiber
 * to the ready queue, and the loop resumes it.
 *
 * A tick: wake the waits deferred to it ({@see defer()}) and the due timers; resume the fibers that were ready
 * when the tick began, draining the microtasks after each one, so what they make ready waits for the next tick
 * (two fibers that ping-pong cannot starve the streams and the timers); then wait for the streams and the signals:
 * not at all if something is ready, until the nearest timer, or without a timeout. On a virtual clock the loop
 * never sleeps for a timer: where it would, it jumps the clock to the timer.
 *
 * A run ends when its fiber ends. If nothing is ready, and no referenced timer, no stream and no signal is
 * waited for while the fiber waits, it waits forever: the loop throws {@see Deadlock} into its wait.
 *
 * @internal
 */
final class Loop
{
    /**
     * The loop of the run in progress: the one {@see ContinuationImpl::park()} gives its fiber to.
     */
    public static ?self $current = null;

    /**
     * Starts at 1 with the first tick: a counter of calls per tick compares it ({@see Pacer}).
     */
    public private(set) int $tick = 0;

    /**
     * The fiber of {@see run()}, null between the runs.
     *
     * @var ?\Fiber<mixed, mixed, mixed, mixed>
     */
    public private(set) ?\Fiber $main = null;

    /**
     * The wait the fiber of {@see run()} is parked in, set by {@see ContinuationImpl::park()}:
     * an error that has escaped to the loop, and a deadlock, are thrown there.
     *
     * @var ?ContinuationImpl<mixed>
     */
    public ?ContinuationImpl $mainWait = null;

    /**
     * @var list<\Fiber<mixed, mixed, mixed, mixed>>
     */
    private array $ready = [];

    /**
     * @var list<(\Closure(): void)|\Fiber<mixed, mixed, mixed, mixed>> a fiber is started
     */
    private array $microtasks = [];

    /**
     * @var list<Waiter> woken at the start of the next tick
     */
    private array $deferred = [];

    /**
     * @var \SplMinHeap<array{int, int, Timer}> [expiration, order, timer]: lazy deletion, an entry whose timer has
     *                                         no waiter is outdated
     */
    private \SplMinHeap $timers;

    private int $order = 0;

    private int $pendingTimers = 0;

    private int $referencedTimers = 0;

    private int $outdatedTimers = 0;

    private ?Context $context = null;

    /**
     * @var array<int, WatchedStream> by the id of the resource
     */
    private array $streams = [];

    /**
     * @var array<int, WatchedStream> whose watchers to bring up to date before the next wait
     */
    private array $changedStreams = [];

    /**
     * how many fibers wait for a stream.
     */
    private int $streamWaits = 0;

    /**
     * @var array<int, array<int, \Closure(int): void>> by signal, then by spl_object_id()
     */
    private array $signalHandlers = [];

    /**
     * @var array<int, mixed> the handlers of `pcntl_signal()` that the first trap of a signal has replaced
     */
    private array $previousSignalHandlers = [];

    /**
     * @var array<int, int> caught since they were last dispatched, keyed by themselves, in the order they came
     */
    private array $caughtSignals = [];

    /**
     * Set by the handler of a signal: tells a wait cut short by a signal (EINTR) from a failure.
     */
    private bool $signalled = false;

    private ?bool $previousAsyncSignals = null;

    /**
     * The self-pipe of the signals: the handler writes a byte, so a signal just before a wait ends it.
     *
     * @var ?array{resource, resource}
     */
    private ?array $signalPipe = null;

    private ?WatchedStream $signalStream = null;

    /**
     * @var array<int, \Fiber<mixed, mixed, mixed, mixed>> started by this loop and not terminated, by spl_object_id()
     */
    private array $fibers = [];

    /**
     * @var list<\Throwable> escaped from a microtask, a wake or a fiber, not thrown into {@see} yet
     */
    private array $errors = [];

    /**
     * @param ?VirtualClock $clock null for the monotonic clock of the system, `hrtime()`
     */
    public function __construct(
        public readonly ?VirtualClock $clock = null,
    ) {
        $this->timers = new \SplMinHeap();
    }

    public function now(): int
    {
        // @phpstan-ignore return.type (hrtime() returns a float only on 32-bit PHP, which no package supports)
        return $this->clock->now ?? hrtime(true);
    }

    /**
     * Runs $fn in a fiber, and the loop until the fiber ends.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     * @throws \LogicException in a fiber, or if the loop is running
     */
    public function run(\Closure $fn): mixed
    {
        if (\Fiber::getCurrent() !== null) {
            throw new \LogicException('The event loop runs only in {main}, not in a fiber.');
        }

        if ($this->main !== null) {
            throw new \LogicException('The event loop is already running: it cannot run again inside.');
        }

        $previous = self::$current;
        self::$current = $this;
        $main = new \Fiber($fn);
        $this->main = $main;
        $this->fibers[spl_object_id($main)] = $main;
        $this->ready[] = $main;
        $error = null;

        try {
            while (!$main->isTerminated()) {
                $this->tick();
            }

            try {
                /** @var T */
                $result = $main->getReturn();
            } catch (\FiberError) {
                // It has thrown: the step that ran it has kept the error.
                $result = null;
            }

            $error = array_shift($this->errors);
        } finally {
            $this->main = null;
            $this->mainWait = null;
            self::$current = $previous;
        }

        if ($error !== null) {
            foreach ($this->errors as $suppressed) {
                Suppressed::add($error, $suppressed);
            }

            $this->errors = [];

            throw $error;
        }

        /** @var T */
        return $result;
    }

    /**
     * Starts a fiber for $fn with the microtasks: after the current fiber suspends, before the next ready one.
     *
     * @param \Closure(): mixed $fn what it returns is dropped
     */
    public function start(\Closure $fn): void
    {
        $fiber = new \Fiber($fn);
        $this->fibers[spl_object_id($fiber)] = $fiber;
        $this->microtasks[] = $fiber;
    }

    /**
     * Calls $fn after the current fiber suspends, before the next ready one, in {main}. A throw goes
     * to the fiber of {@see run()}.
     *
     * @param \Closure(): void $fn
     */
    public function queue(\Closure $fn): void
    {
        $this->microtasks[] = $fn;
    }

    /**
     * Resumes $fiber in the next tick, or in this one if it has not begun to resume its ready fibers yet.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public function resume(\Fiber $fiber): void
    {
        $this->ready[] = $fiber;
    }

    /**
     * Wakes $waiter at the start of the next tick: a yield of a fiber that lets the others, the streams
     * and the timers in.
     */
    public function defer(Waiter $waiter): void
    {
        $this->deferred[] = $waiter;
    }

    /**
     * Wakes $waiter once $nanoseconds have passed, in the first tick after. Cancel it with {@see cancel()}.
     *
     * @param bool $referenced false for a timer that does not keep a waiting fiber from a {@see Deadlock}
     */
    public function delay(int $nanoseconds, Waiter $waiter, bool $referenced = true): Timer
    {
        $expiration = $this->now() + max(0, $nanoseconds);
        // @phpstan-ignore function.alreadyNarrowedType (an int overflow turns the sum into a float)
        $expiration = \is_int($expiration) ? $expiration : PHP_INT_MAX;
        $timer = new Timer(expiration: $expiration, order: ++$this->order, waiter: $waiter, referenced: $referenced);
        $this->timers->insert([$expiration, $timer->order, $timer]);
        ++$this->pendingTimers;

        if ($referenced) {
            ++$this->referencedTimers;
        }

        return $timer;
    }

    /**
     * A no-op for a timer that has fired or has been cancelled.
     */
    public function cancel(Timer $timer): void
    {
        if ($timer->waiter === null) {
            return;
        }

        $timer->waiter = null;
        $this->leave($timer);
        ++$this->outdatedTimers;
        $this->compact();
    }

    /**
     * Wakes $waiter once a read of $stream (or a write, if $write) would not block. Once, then it forgets it;
     * {@see unwatch()} forgets it before. A stream that cannot be watched (closed, or without a descriptor, like
     * `php://memory`) never blocks: its waiter is woken at once, and the read or the write tells what is wrong.
     *
     * The owner of a stream ends its waits before closing it: the kernel forgets a closed stream,
     * and nothing would wake them.
     *
     * @param resource $stream
     */
    public function watch(mixed $stream, Waiter $waiter, bool $write): void
    {
        $key = get_resource_id($stream);
        $watched = $this->streams[$key] ??= new WatchedStream($stream);

        if ($write) {
            $watched->writers[spl_object_id($waiter)] = $waiter;
        } else {
            $watched->readers[spl_object_id($waiter)] = $waiter;
        }

        ++$this->streamWaits;
        $this->changed($key, $watched);
    }

    /**
     * A no-op for a waiter that has been woken or forgotten.
     *
     * @param resource $stream
     */
    public function unwatch(mixed $stream, Waiter $waiter, bool $write): void
    {
        $key = get_resource_id($stream);
        $watched = $this->streams[$key] ?? null;
        $id = spl_object_id($waiter);

        if ($watched === null) {
            return;
        }

        if ($write ? !isset($watched->writers[$id]) : !isset($watched->readers[$id])) {
            return;
        }

        if ($write) {
            unset($watched->writers[$id]);
        } else {
            unset($watched->readers[$id]);
        }

        --$this->streamWaits;
        $this->changed($key, $watched);
    }

    /**
     * Calls $handler with the signal every time the process receives it, until {@see offSignal()}: instead of its
     * default action, or the handler of `pcntl_signal()` it had, which comes back once the last one is gone.
     * A handler is called in {main} between the fibers, so it must not wait.
     *
     * @param positive-int $signal
     * @param \Closure(int): void $handler
     */
    public function onSignal(int $signal, \Closure $handler): void
    {
        if (!isset($this->signalHandlers[$signal])) {
            if ($this->signalHandlers === []) {
                $this->openSignalPipe();
            }

            $this->previousSignalHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, $this->catchSignal(...));
        }

        $this->signalHandlers[$signal][spl_object_id($handler)] = $handler;
    }

    /**
     * A no-op for a handler that is not there.
     *
     * @param positive-int $signal
     * @param \Closure(int): void $handler
     */
    public function offSignal(int $signal, \Closure $handler): void
    {
        unset($this->signalHandlers[$signal][spl_object_id($handler)]);

        if (($this->signalHandlers[$signal] ?? null) !== []) {
            return;
        }

        unset($this->signalHandlers[$signal], $this->caughtSignals[$signal]);
        /** @var callable|int $previous */
        $previous = $this->previousSignalHandlers[$signal] ?? SIG_DFL;
        unset($this->previousSignalHandlers[$signal]);
        pcntl_signal($signal, $previous);

        if ($this->signalHandlers === []) {
            $this->closeSignalPipe();
        }
    }

    private function tick(): void
    {
        ++$this->tick;

        if ($this->deferred !== []) {
            $deferred = $this->deferred;
            $this->deferred = [];

            foreach ($deferred as $waiter) {
                $this->wake($waiter);
            }
        }

        if ($this->pendingTimers > 0) {
            $this->fireTimers($this->now());
        }

        $this->drainMicrotasks();
        $ready = $this->ready;
        $this->ready = [];

        foreach ($ready as $fiber) {
            $this->step($fiber);
        }

        // The run is over: what is left waits for the next one, and must not block this one.
        if ($this->main?->isTerminated() !== false) {
            return;
        }

        $this->wait();
    }

    /**
     * Resumes $fiber, or starts it, then drains the microtasks and throws an error that has escaped
     * to the loop into the wait of the fiber of {@see run()}.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private function step(\Fiber $fiber): void
    {
        $this->runFiber($fiber);
        $this->drainMicrotasks();
    }

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private function runFiber(\Fiber $fiber): void
    {
        try {
            if ($fiber->isStarted()) {
                $fiber->resume();
            } else {
                $fiber->start();
            }
        } catch (\Throwable $error) {
            if ($fiber === $this->main) {
                // Out of run(), before the errors that have escaped meanwhile.
                array_unshift($this->errors, $error);
            } else {
                $this->errors[] = $error;
            }
        }

        if ($fiber->isTerminated()) {
            unset($this->fibers[spl_object_id($fiber)]);
        }
    }

    private function drainMicrotasks(): void
    {
        // A microtask may queue more: they are drained too, in the order they came.
        for ($i = 0; $i < \count($this->microtasks); ++$i) {
            $microtask = $this->microtasks[$i];

            if ($microtask instanceof \Fiber) {
                $this->runFiber($microtask);

                continue;
            }

            try {
                $microtask();
            } catch (\Throwable $error) {
                $this->errors[] = $error;
            }
        }

        $this->microtasks = [];

        if ($this->errors !== [] && $this->main?->isTerminated() === false) {
            $this->throwErrors();
        }
    }

    /**
     * Throws the first error that has escaped to the loop into the wait of the fiber of {@see run()}.
     * If its wait has already been woken, the error waits for the next one: a value it has been handed is not lost.
     */
    private function throwErrors(): void
    {
        $error = $this->errors[0] ?? null;

        if ($error !== null && $this->mainWait?->fail($error) === true) {
            array_shift($this->errors);
        }
    }

    private function wake(Waiter $waiter): void
    {
        try {
            $waiter->wake();
        } catch (\Throwable $error) {
            $this->errors[] = $error;
        }
    }

    private function fireTimers(int $now): void
    {
        while (!$this->timers->isEmpty()) {
            [$expiration, , $timer] = $this->timers->top();
            $waiter = $timer->waiter;

            if ($waiter === null) {
                $this->timers->extract();
                --$this->outdatedTimers;

                continue;
            }

            if ($expiration > $now) {
                return;
            }

            $this->timers->extract();
            $timer->waiter = null;
            $this->leave($timer);
            $this->wake($waiter);
        }
    }

    /**
     * The timer is no longer pending: fired or cancelled.
     */
    private function leave(Timer $timer): void
    {
        --$this->pendingTimers;

        if ($timer->referenced) {
            --$this->referencedTimers;
        }
    }

    /**
     * Rebuilds the heap once most of its entries are outdated: almost every timer of a timeout is cancelled
     * long before it is due, and must not pile up. Each rebuild drops more entries than it keeps.
     */
    private function compact(): void
    {
        if ($this->outdatedTimers < 64 || $this->outdatedTimers <= $this->pendingTimers) {
            return;
        }

        /** @var \SplMinHeap<array{int, int, Timer}> $timers */
        $timers = new \SplMinHeap();

        foreach ($this->timers as $entry) {
            if ($entry[2]->waiter !== null) {
                $timers->insert($entry);
            }
        }

        $this->timers = $timers;
        $this->outdatedTimers = 0;
    }

    /**
     * The nearest pending timer, dropping the outdated entries on the way.
     */
    private function nextTimer(): ?Timer
    {
        while (!$this->timers->isEmpty()) {
            $timer = $this->timers->top()[2];

            if ($timer->waiter !== null) {
                return $timer;
            }

            $this->timers->extract();
            --$this->outdatedTimers;
        }

        return null;
    }

    private function changed(int $key, WatchedStream $watched): void
    {
        if (!$watched->changed) {
            $watched->changed = true;
            $this->changedStreams[$key] = $watched;
        }
    }

    /**
     * Waits for the streams and the signals: not at all if something is ready, until the nearest timer,
     * or without a timeout. On a virtual clock, it jumps the clock to the timer instead of waiting for it.
     */
    private function wait(): void
    {
        $this->updateWatchers();

        if ($this->ready !== [] || $this->deferred !== []) {
            $this->poll(0);

            return;
        }

        $timer = $this->nextTimer();

        if ($this->referencedTimers === 0 && $this->streamWaits === 0 && $this->signalHandlers === []) {
            $this->deadlock();

            return;
        }

        if ($timer === null) {
            $this->poll(null);

            return;
        }

        if ($this->clock === null) {
            $this->poll(max(0, $timer->expiration - $this->now()));

            return;
        }

        // The clock jumps only where the loop would sleep: nothing is ready, and no stream is.
        if (!$this->poll(0)) {
            $this->clock->jump($timer->expiration);
        }
    }

    /**
     * @param ?non-negative-int $timeout in nanoseconds, null for none
     * @return bool whether a stream or a signal was ready
     */
    private function poll(?int $timeout): bool
    {
        if ($this->streamWaits === 0 && $this->signalStream === null) {
            if ($timeout !== null && $timeout > 0) {
                // Nothing to watch: a sleep until the timer.
                $this->context()->wait(ns(min($timeout, Scope::LONGEST_DURATION)));
            }

            return false;
        }

        $this->signalled = false;

        try {
            // A cap on the events would leave the rest for the next tick, and level-triggered watchers keep them,
            // but nothing asks for one: every ready stream is woken in the tick it is found ready.
            $watchers = $this->context()->wait($timeout === null ? null : ns(min($timeout, Scope::LONGEST_DURATION)));
        } catch (FailedPollWaitException $exception) {
            /**
             * The handler of a signal has run: the wait is cut short (EINTR), its signal is in the pipe.
             * @phpstan-ignore ternary.alwaysFalse (the handler of a signal sets it during the wait)
             */
            $watchers = $this->signalled ? [] : throw $exception;
        }

        $signalled = false;

        foreach ($watchers as $watcher) {
            /** @var WatchedStream $watched */
            $watched = $watcher->getData();

            if ($watched === $this->signalStream) {
                $signalled = true;

                continue;
            }

            $failed = $watcher->hasTriggered(Event::Error) || $watcher->hasTriggered(Event::HangUp);

            if ($failed || $watcher->hasTriggered(Event::Read) || $watcher->hasTriggered(Event::ReadHangUp)) {
                $this->wakeAll($watched, write: false);
            }

            if ($failed || $watcher->hasTriggered(Event::Write)) {
                $this->wakeAll($watched, write: true);
            }
        }

        // The pipe is drained even if the handlers have been removed since: a byte left in it would end every wait.
        if ($signalled || $this->caughtSignals !== []) {
            $this->dispatchSignals();
        }

        return $watchers !== [];
    }

    private function wakeAll(WatchedStream $watched, bool $write): void
    {
        $waiters = $write ? $watched->writers : $watched->readers;

        if ($waiters === []) {
            return;
        }

        if ($write) {
            $watched->writers = [];
        } else {
            $watched->readers = [];
        }

        $this->streamWaits -= \count($waiters);
        $this->changed(get_resource_id($watched->stream), $watched);

        foreach ($waiters as $waiter) {
            $this->wake($waiter);
        }
    }

    /**
     * Brings the watchers of the changed streams up to date with their waits: lazily, right before a wait,
     * so a fiber that waits for the same stream again in the tick it was woken costs no call to the kernel.
     */
    private function updateWatchers(): void
    {
        if ($this->changedStreams === []) {
            return;
        }

        $changed = $this->changedStreams;
        $this->changedStreams = [];

        foreach ($changed as $key => $watched) {
            $watched->changed = false;
            $events = [];

            if ($watched->readers !== []) {
                $events[] = Event::Read;
            }

            if ($watched->writers !== []) {
                $events[] = Event::Write;
            }

            $watcher = $watched->watcher;

            if ($events === []) {
                // Closed by its owner, the stream is no longer watched by the kernel: nothing to remove.
                if ($watcher?->isActive() === true) {
                    $watcher->remove();
                }

                unset($this->streams[$key]);

                continue;
            }

            if ($watcher === null) {
                $this->addWatcher($watched, $events);
            } elseif ($watcher->isActive() && $watcher->getWatchedEvents() !== $events) {
                $watcher->modifyEvents($events);
            }
        }
    }

    /**
     * @param non-empty-list<Event> $events
     */
    private function addWatcher(WatchedStream $watched, array $events): void
    {
        try {
            // A stream without a descriptor warns, and throws the exception that tells it.
            $watched->watcher = @$this->context()->add(new \StreamPollHandle($watched->stream), $events, $watched);
        } catch (InvalidHandleException|\TypeError) {
            // Closed, or without a descriptor: it never blocks, a read or a write tells what is wrong with it.
            $this->wakeAll($watched, write: false);
            $this->wakeAll($watched, write: true);
        }
    }

    private function context(): Context
    {
        return $this->context ??= new Context();
    }

    private function openSignalPipe(): void
    {
        /** @var array{resource, resource} $pipe */
        $pipe = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        stream_set_blocking($pipe[0], false);
        stream_set_blocking($pipe[1], false);
        $this->signalPipe = $pipe;
        $this->signalStream = new WatchedStream($pipe[0]);
        $this->signalStream->watcher = $this->context()->add(
            handle: new \StreamPollHandle($pipe[0]),
            events: [Event::Read],
            data: $this->signalStream,
        );
        // Asynchronous: the handler runs as soon as the signal comes, and a wait is cut short by it.
        $this->previousAsyncSignals = pcntl_async_signals(true);
    }

    private function closeSignalPipe(): void
    {
        if ($this->signalPipe === null) {
            return;
        }

        $this->signalStream?->watcher?->remove();
        $this->signalStream = null;
        fclose($this->signalPipe[0]);
        fclose($this->signalPipe[1]);
        $this->signalPipe = null;
        pcntl_async_signals($this->previousAsyncSignals ?? false);
        $this->previousAsyncSignals = null;
        $this->caughtSignals = [];
    }

    /**
     * The handler of `pcntl_signal()`: it runs in the middle of any code, so it only takes note.
     */
    private function catchSignal(int $signal): void
    {
        $this->caughtSignals[$signal] = $signal;
        $this->signalled = true;

        if ($this->signalPipe !== null) {
            // A full pipe already ends the wait: the byte is not needed then.
            @fwrite($this->signalPipe[1], "\0");
        }
    }

    private function dispatchSignals(): void
    {
        if ($this->signalPipe !== null) {
            while (@fread($this->signalPipe[0], 1024) !== '') {
                // drains the bytes of the signals caught so far
            }
        }

        $caught = $this->caughtSignals;
        $this->caughtSignals = [];

        foreach ($caught as $signal) {
            foreach ($this->signalHandlers[$signal] ?? [] as $handler) {
                try {
                    $handler($signal);
                } catch (\Throwable $error) {
                    $this->errors[] = $error;
                }
            }
        }
    }

    /**
     * Nothing is ready and nothing can wake a fiber: the fiber of {@see run()} waits forever.
     */
    private function deadlock(): void
    {
        $main = $this->main;

        if ($main === null || $main->isTerminated()) {
            return;
        }

        $deadlock = new Deadlock($this->describeDeadlock());

        if ($this->mainWait?->fail($deadlock) !== true) {
            // Suspended past the library: nothing can resume it.
            throw $deadlock;
        }
    }

    private function describeDeadlock(): string
    {
        $places = [];

        foreach ($this->fibers as $fiber) {
            if ($fiber->isSuspended()) {
                $places[] = '  ' . self::place($fiber);
            }
        }

        return "Deadlock: the fibers wait, and no timer, stream or signal can wake them. Suspended at:\n"
            . implode("\n", $places);
    }

    /**
     * Where a suspended fiber waits: the first frame outside this library.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private static function place(\Fiber $fiber): string
    {
        $library = \dirname(__DIR__) . \DIRECTORY_SEPARATOR;
        $place = 'an unknown place';

        /** @var list<array{file?: string, line?: int}> $trace */
        $trace = new \ReflectionFiber($fiber)->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;

            if ($file === '' || $line < 1) {
                continue;
            }

            if (!str_starts_with($file, $library)) {
                return (string) Location::of($file, $line);
            }

            $place = (string) Location::of($file, $line);
        }

        return $place;
    }
}

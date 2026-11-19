<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedHandleAddException;
use Io\Poll\FailedPollWaitException;
use Io\Poll\InvalidHandleException;
use Io\Poll\Watcher;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Thesis\Async\Deadlock;
use Thesis\Async\Pacer;
use Thesis\SourceLocation\Location;
use function Thesis\Time\ns;

/**
 * The scheduler of fibers on `Io\Poll`. It runs in {main}, or in the fiber that runs an outer loop (a nested
 * `virtualTime()`, which blocks the outer one meanwhile), and the code it runs only in fibers of its own.
 *
 * It knows five things: the ready fibers, the timers, the streams, the signals and the microtasks. A wait hands
 * it a {@see Waiter}, which it wakes once the event has come; a woken {@see ContinuationImpl} puts its fiber
 * to the ready queue, and the loop resumes it. A task runs in a fiber of its pool: a fiber whose task has ended
 * waits for the next one instead of ending, since a new fiber costs its stack ({@see work()}).
 *
 * A tick: wake the waits deferred to it ({@see defer()}) and the due timers; resume the fibers that were ready
 * when the tick began, draining the microtasks after each one, so what they make ready waits for the next tick
 * (two fibers that ping-pong cannot starve the streams and the timers); then wait for the streams and the signals:
 * not at all if something is ready, until the nearest timer, or without a timeout. On a virtual clock the loop
 * never sleeps for a timer: where it would, it jumps the clock to the timer.
 *
 * A loop that is dropped is released first ({@see release()}): what a fiber left in it holds could keep it.
 *
 * A run ends when its fiber ends. If nothing is ready, and no referenced timer, no stream and no signal is
 * waited for while the fiber waits, it waits forever: the loop throws {@see Deadlock} into its wait.
 *
 * @internal
 */
final class Loop
{
    /**
     * The longest wait of the kernel while signals are handled: the delay of a signal that comes in the gap
     * before the wait, see {@see poll()}.
     */
    private const int SIGNAL_GAP = 100 * 1_000_000;

    /**
     * The most fibers of the pool that wait for a task: a burst of tasks leaves no more than these behind.
     */
    private const int IDLE_FIBERS = 128;

    /**
     * The loop of the run in progress, or the one of the process: see {@see get()}.
     */
    private static ?self $current = null;

    /**
     * The wait that the fiber just suspended has parked in: {@see ContinuationImpl::park()} leaves it here,
     * the loop takes it once the fiber it has resumed returns to it.
     *
     * @var ?ContinuationImpl<mixed>
     */
    public static ?ContinuationImpl $parked = null;

    /**
     * The task that the fiber of the pool about to be resumed runs, see {@see work()}.
     *
     * @var ?\Closure(): mixed
     */
    private static ?\Closure $task = null;

    /**
     * Set by a fiber of the pool whose task has ended, before it suspends to wait for the next one.
     */
    private static bool $taskEnded = false;

    /**
     * What the task that has just ended has thrown.
     */
    private static ?\Throwable $taskFailure = null;

    /**
     * The number of the current tick, unique among the ticks of every loop: a counter of calls per tick
     * compares it ({@see Pacer}), and a nested loop ({@see run()} in a fiber) must not share the numbers
     * of the outer one.
     */
    public private(set) int $tick = 0;

    /**
     * The fiber of {@see run()}, null between the runs.
     *
     * @var ?\Fiber<mixed, mixed, mixed, mixed>
     */
    public private(set) ?\Fiber $main = null;

    /**
     * The fiber that {@see run()} was called in, null for {main}: the scheduler runs in it, so it cannot wait.
     *
     * @var ?\Fiber<mixed, mixed, mixed, mixed>
     */
    public private(set) ?\Fiber $host = null;

    /**
     * The wait the fiber of {@see run()} is parked in: an error that has escaped to the loop,
     * and a deadlock, are thrown there.
     *
     * @var ?ContinuationImpl<mixed>
     */
    private ?ContinuationImpl $mainWait = null;

    /**
     * @var list<\Fiber<mixed, mixed, mixed, mixed>>
     */
    private array $ready = [];

    /**
     * @var list<(\Closure(): mixed)|Callback> a closure is a task to start, a {@see Callback} is called
     */
    private array $microtasks = [];

    /**
     * @var list<\Fiber<mixed, mixed, mixed, mixed>> the fibers of the pool that wait for a task, the last one first
     */
    private array $idleFibers = [];

    /**
     * @var array<int, Waiter> woken at the start of the next tick, by spl_object_id()
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

    private ?bool $previousAsyncSignals = null;

    /**
     * The self-pipe of the signals: the handler writes a byte, so a signal just before a wait ends it.
     *
     * @var ?array{resource, resource}
     */
    private ?array $signalPipe = null;

    private ?WatchedStream $signalStream = null;

    /**
     * @var array<int, \Fiber<mixed, mixed, mixed, mixed>> that run the fiber of {@see run()} or a task,
     *     by spl_object_id(): not the idle ones of the pool
     */
    private array $fibers = [];

    /**
     * @var list<\Throwable> escaped from a microtask, a wake or a fiber, not thrown into {@see} yet
     */
    private array $errors = [];

    private readonly ?Randomizer $shuffle;

    /**
     * @param ?VirtualClock $clock null for the monotonic clock of the system, `hrtime()`
     * @param ?int $seed shuffles, by this seed, the fibers ready at the start of a tick, so a test meets the orders
     *     the kernel and the timing may give; null keeps the order they became ready in
     */
    public function __construct(
        public readonly ?VirtualClock $clock = null,
        ?int $seed = null,
    ) {
        $this->timers = new \SplMinHeap();
        $this->shuffle = $seed === null ? null : new Randomizer(new Xoshiro256StarStar($seed));
    }

    /**
     * The loop that runs now, or the one of the process, created on first use: a wait parks on it,
     * and `group()` called in {main} runs it.
     */
    public static function get(): self
    {
        return self::$current ??= new self();
    }

    /**
     * Nothing is waited for or queued: no pending timer, stream wait, signal handler, deferred wake or microtask.
     */
    public bool $isIdle {
        get => $this->pendingTimers === 0
            && $this->streamWaits === 0
            && $this->signalHandlers === []
            && $this->deferred === []
            && $this->microtasks === [];
    }

    public function now(): int
    {
        // @phpstan-ignore return.type (hrtime() returns a float only on 32-bit PHP, which no package supports)
        return $this->clock->now ?? hrtime(true);
    }

    /**
     * Runs $fn in a fiber, and the loop until the fiber ends. While it runs, it is the loop of {@see get()}.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     * @throws \LogicException if the loop is running
     */
    public function run(\Closure $fn): mixed
    {
        if ($this->main !== null) {
            throw new \LogicException('The event loop is already running: it cannot run again inside.');
        }

        $previous = self::$current;
        self::$current = $this;
        $main = new \Fiber($fn);
        $this->main = $main;
        $this->host = \Fiber::getCurrent();
        $this->fibers[spl_object_id($main)] = $main;
        $this->ready[] = $main;
        $error = null;

        try {
            // Between the fibers the code of the loop (a callback of onCancel(), a watchdog, a signal handler)
            // runs in the fiber or {main} that has called run(): it must not see their scope and values.
            Scope::runInRoot(function () use ($main) {
                while (!$main->isTerminated()) {
                    $this->tick();
                }
            });

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
            $this->host = null;
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
     * Drops what a run has left: the fibers, the timers, the streams with the descriptor of the kernel,
     * the handlers of the signals. A fiber of a closure bound to an object that holds the wait of the fiber,
     * such as a task of the user, is a cycle that the collector of PHP 8.6 never frees (php/php-src#25540),
     * and its wait holds the loop: the loop alone goes away with it then, not its descriptors.
     *
     * @throws \LogicException if the loop is running
     */
    public function release(): void
    {
        if ($this->main !== null) {
            throw new \LogicException('The event loop is running: it cannot be released.');
        }

        foreach ($this->signalHandlers as $signal => $_) {
            /** @var callable|int $previous */
            $previous = $this->previousSignalHandlers[$signal] ?? SIG_DFL;
            pcntl_signal($signal, $previous);
        }

        $this->signalHandlers = [];
        $this->previousSignalHandlers = [];
        $this->closeSignalPipe();

        foreach ($this->streams as $watched) {
            if ($watched->watcher?->isActive() === true) {
                $watched->watcher->remove();
            }

            // A cycle through the data of the watcher, see updateWatchers().
            $watched->watcher = null;
        }

        $this->streams = [];
        $this->changedStreams = [];
        $this->streamWaits = 0;
        $this->context = null;

        // Done, as if fired: a cancel that comes later (a fiber destroyed in its wait, the cancel of a watchdog)
        // finds nothing to do, and the counters of the next run stay right.
        foreach ($this->timers as [, , $timer]) {
            $timer->waiter = null;
        }

        $this->timers = new \SplMinHeap();
        $this->pendingTimers = 0;
        $this->referencedTimers = 0;
        $this->outdatedTimers = 0;
        $this->ready = [];
        $this->microtasks = [];
        $this->deferred = [];
        $this->fibers = [];
        $this->idleFibers = [];
        $this->errors = [];
    }

    /**
     * Starts $task in a fiber with the microtasks: after the current fiber suspends, before the next ready one.
     * The fiber is taken from the pool when the task starts, so a task that ends without a wait leaves its fiber
     * to the next one. A throw goes to the fiber of {@see run()}.
     *
     * @param \Closure(): mixed $task what it returns is dropped
     */
    public function start(\Closure $task): void
    {
        $this->microtasks[] = $task;
    }

    /**
     * Calls $fn after the current fiber suspends, before the next ready one, in {main}. A throw goes
     * to the fiber of {@see run()}.
     *
     * @param \Closure(): void $fn
     */
    public function queue(\Closure $fn): void
    {
        $this->microtasks[] = new Callback($fn);
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
        $this->deferred[spl_object_id($waiter)] = $waiter;
    }

    /**
     * A no-op for a waiter that has been woken or was not deferred.
     */
    public function undefer(Waiter $waiter): void
    {
        unset($this->deferred[spl_object_id($waiter)]);
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

        // The timer of a timeout is cancelled while it is the nearest one, mostly: gone at once, it is not outdated.
        if ($this->timers->top()[2] === $timer) {
            $this->timers->extract();

            return;
        }

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
            // First: it throws \ValueError for a number that is no signal, before anything is opened.
            $previous = pcntl_signal_get_handler($signal);

            if ($this->signalHandlers === []) {
                $this->openSignalPipe();
            }

            $this->previousSignalHandlers[$signal] = $previous;
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

    /**
     * The function of a fiber of the pool: runs a task, then waits for the next one, which {@see drainMicrotasks()}
     * leaves in {@see $task} before it resumes the fiber. Only statics pass the task and its end: a fiber suspended
     * with a value keeps it from the cycle collector (`agents/fibers.md`), and a closure that captured an object
     * would keep it while the fiber waits. One frame under the task: a trace looks through a few frames only.
     */
    private static function work(): void
    {
        // The fiber waits here for its next task until the pool is full.
        while (true) {
            /** @var \Closure(): mixed $task the loop has left it */
            $task = self::$task;
            self::$task = null;

            try {
                $task();
            } catch (\Throwable $error) {
                self::$taskFailure = $error;
            }

            // Not kept while the fiber waits: the task, what it holds and its failure would outlive it.
            unset($task, $error);
            self::$taskEnded = true;

            // The loop that has run the task is the current one again. A full pool: the fiber ends here,
            // which costs less than a suspended one the loop drops, unwound by the engine.
            if (\count(self::get()->idleFibers) >= self::IDLE_FIBERS) {
                return;
            }

            \Fiber::suspend();
        }
    }

    /**
     * @return ?ContinuationImpl<mixed>
     */
    private static function takeParked(): ?ContinuationImpl
    {
        $parked = self::$parked;
        self::$parked = null;

        return $parked;
    }

    private function tick(): void
    {
        /** @var int */
        static $ticks = 0;
        $this->tick = ++$ticks;

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

        if ($this->shuffle !== null && \count($ready) > 1) {
            $ready = $this->shuffle->shuffleArray($ready);
        }

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
        // Left by a fiber of the user that has parked and returned to the user, not to the loop.
        self::$parked = null;

        try {
            if ($fiber->isStarted()) {
                $fiber->resume();
            } else {
                $fiber->start();
            }

            if (self::$taskEnded) {
                $this->taskEnded($fiber);

                return;
            }

            $wait = self::takeParked();

            // A fiber of the user has parked and returned to this one, which has ended: no fiber is there
            // to pass the suspension to, and nothing resumes that wait, as with any fiber left by its owner.
            if ($wait !== null && !$fiber->isTerminated()) {
                if ($fiber === $this->main) {
                    $this->mainWait = $wait;
                }

                $wait->suspended($this, $fiber);
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

            if ($microtask instanceof Callback) {
                $this->wake($microtask);

                continue;
            }

            // Here, not in a method: a trace looks through a few frames only.
            $fiber = array_pop($this->idleFibers) ?? new \Fiber(self::work(...));
            $this->fibers[spl_object_id($fiber)] = $fiber;
            self::$task = $microtask;
            $this->runFiber($fiber);
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

    /**
     * The task of $fiber, a fiber of the pool, has ended: the fiber waits for the next one, unless it has ended
     * too, the pool being full.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private function taskEnded(\Fiber $fiber): void
    {
        self::$taskEnded = false;
        unset($this->fibers[spl_object_id($fiber)]);

        if (self::$taskFailure !== null) {
            $this->errors[] = self::$taskFailure;
            self::$taskFailure = null;
        }

        if (!$fiber->isTerminated()) {
            $this->idleFibers[] = $fiber;
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
                $this->waitForEvents($timeout);
            }

            return false;
        }

        if ($this->signalHandlers !== []) {
            // A signal that comes after the last check of the engine, right before the wait, is only noted:
            // its handler runs at the next check, so no byte is in the pipe, and the wait is not cut short.
            // PHP has no way to close the gap (no signalfd, no pselect), so such a wait is cut short by time.
            $timeout = min($timeout ?? self::SIGNAL_GAP, self::SIGNAL_GAP);
        }

        $watchers = $this->waitForEvents($timeout);

        if ($this->signalHandlers !== []) {
            // Runs the handlers of the signals noted in the gap.
            pcntl_signal_dispatch();
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
        // A signal is an event as a stream is, even one whose handler has run only after the wait: the virtual
        // clock must not jump past what it has woken.
        if ($signalled || $this->caughtSignals !== []) {
            $this->dispatchSignals();

            return true;
        }

        return $watchers !== [];
    }

    /**
     * A wait of the kernel. A signal cuts it short (EINTR), whoever handles it, the loop, the user, or nobody
     * (SIGCONT after SIGSTOP): that is no failure, only a wait with no events.
     *
     * @return list<Watcher>
     */
    private function waitForEvents(?int $timeout): array
    {
        try {
            // A cap on the events would leave the rest for the next tick, and level-triggered watchers keep them,
            // but nothing asks for one: every ready stream is woken in the tick it is found ready.
            return $this->context()->wait($timeout === null ? null : ns(min($timeout, Scope::LONGEST_DURATION)));
        } catch (FailedPollWaitException $exception) {
            return $exception->getCode() === FailedPollWaitException::ERROR_INTERRUPTED ? [] : throw $exception;
        }
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

            if ($watcher !== null && !$watcher->isActive()) {
                // Its descriptor is closed: the kernel has dropped it, and a new watch finds the stream closed.
                $watched->watcher = null;
                $watcher = null;
            }

            if ($events === []) {
                $watcher?->remove();
                // The watcher holds the stream as its data: a cycle that would keep the descriptor open
                // until the collector runs, and a dropped socket would not be closed for its peer.
                $watched->watcher = null;

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
        } catch (InvalidHandleException|FailedHandleAddException|\TypeError $error) {
            // Any other failure to add (no memory, the limit of watches) is of the system, not of the stream:
            // woken at once, a socket would wait again and spin the loop.
            if ($error instanceof FailedHandleAddException && $error->getCode() !== $error::ERROR_NOSUPPORT) {
                throw $error;
            }

            // Closed, without a descriptor, or one that the kernel does not watch (/dev/null, php://temp):
            // it never blocks, a read or a write tells what is wrong with it.
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
        error_clear_last();
        /** @var array{resource, resource}|false $pipe */
        $pipe = @stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);

        if ($pipe === false) {
            throw new \RuntimeException(\sprintf(
                'Failed to open the self-pipe of the signals: %s.',
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

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

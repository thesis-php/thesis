<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;

/**
 * The Revolt driver of `Thesis\Async\VirtualTime\run()`: a decorator of a regular driver that keeps the timers itself,
 * on a {@see VirtualClock}.
 *
 * The clock stands still while code runs: CPU work takes no time, {@see VirtualClock::advance()} models it.
 * When nothing can run, the clock jumps to the nearest timer (Trio's autojump), so a wait of an hour
 * is over at once, and timers fire exactly in the order of their deadlines.
 *
 * Everything but the timers is the inner driver's: streams and signals are real, and so are the suspensions.
 * While a timer is pending, a pump (a defer of the inner driver) runs on every tick and keeps the inner loop
 * from blocking. Every callback given to this driver counts as activity, when it is given, enabled and when it runs;
 * when there has been none during two runs of the pump in a row, nothing can run, and the clock jumps. One is not
 * enough: the pump is a defer, and the defers of a tick run before the callbacks of the streams and signals
 * the inner driver has found ready in it. So only a stream that is to become ready from outside the process
 * may be jumped past. Without a timer there is no pump, and the inner
 * driver blocks on the streams and signals for real. When nothing at all is left,
 * Revolt ends the loop: a deadlock fails at once.
 *
 * A due timer fires through a defer of its own, as Revolt's timers fire as callbacks of their own:
 * the microtasks queued by one, such as a resumed fiber, run before the next one.
 *
 * @internal
 */
final class VirtualTimeDriver implements Driver
{
    /**
     * @var array<string, VirtualTimer>
     */
    private array $timers = [];

    /**
     * @var \SplMinHeap<array{int, int, string}> [expiration, order, callback id]; an entry is valid while its order
     * is the timer's: {@see enqueue()} gives a new one, {@see disable()} outdates it. The callback id, not the timer:
     * an outdated entry stays until it reaches the top or {@see compact()} drops it, and must not keep the closure
     * of a cancelled timer.
     */
    private \SplMinHeap $queue;

    /**
     * how many entries of the queue are outdated.
     */
    private int $outdated = 0;

    private int $order = 0;

    /**
     * how many timers are pending: enabled, and not handed to their firing defer yet.
     */
    private int $pending = 0;

    /**
     * how many of the pending timers are referenced.
     */
    private int $pendingReferenced = 0;

    private int $nextId = 0;

    /**
     * how many callbacks of the user have been given or have run: the pump compares it between its runs.
     */
    private int $activity = 0;

    private int $activityAtPump = 0;

    /**
     * how many runs of the pump in a row have seen no activity since the previous one.
     */
    private int $idlePumps = 0;

    /**
     * the inner defer of the pump, while there is one.
     */
    private ?string $pumpCallbackId = null;

    /**
     * @var array<string, true> the inner callbacks of this driver: hidden from the identifiers
     */
    private array $own = [];

    /**
     * @param Driver $inner a fresh driver, owned by this one: `Thesis\Async\VirtualTime\run()` checks it
     */
    public function __construct(
        private readonly VirtualClock $clock,
        private readonly Driver $inner,
    ) {
        $this->queue = new \SplMinHeap();
    }

    #[\Override]
    public function run(): void
    {
        $this->inner->run();
    }

    #[\Override]
    public function stop(): void
    {
        $this->inner->stop();
    }

    /**
     * @return Suspension<mixed>
     */
    #[\Override]
    public function getSuspension(): Suspension
    {
        return $this->inner->getSuspension();
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->inner->isRunning();
    }

    /**
     * Revolt does not check what a microtask returns.
     *
     * @param \Closure(mixed...): void $closure
     */
    #[\Override] // @phpstan-ignore method.childParameterType (Revolt documents a signature, but its type is \Closure)
    public function queue(\Closure $closure, mixed ...$args): void
    {
        // A microtask given is work to do, even before it runs, as a callback of counted().
        ++$this->activity;

        $this->inner->queue(function (mixed ...$args) use ($closure) {
            ++$this->activity;
            $closure(...$args);
        }, ...$args);
    }

    #[\Override]
    public function defer(\Closure $closure): string
    {
        return $this->inner->defer($this->counted($closure));
    }

    #[\Override]
    public function delay(float $delay, \Closure $closure): string
    {
        return $this->schedule(seconds: $delay, callback: $closure, repeat: false);
    }

    #[\Override]
    public function repeat(float $interval, \Closure $closure): string
    {
        return $this->schedule(seconds: $interval, callback: $closure, repeat: true);
    }

    #[\Override]
    public function onReadable(mixed $stream, \Closure $closure): string
    {
        return $this->inner->onReadable($stream, $this->counted($closure));
    }

    #[\Override]
    public function onWritable(mixed $stream, \Closure $closure): string
    {
        return $this->inner->onWritable($stream, $this->counted($closure));
    }

    #[\Override]
    public function onSignal(int $signal, \Closure $closure): string
    {
        return $this->inner->onSignal($signal, $this->counted($closure));
    }

    #[\Override]
    public function enable(string $callbackId): string
    {
        $timer = $this->timers[$callbackId] ?? null;

        if ($timer === null) {
            // A callback enabled is work to do, as one given: the pump must not jump past it.
            ++$this->activity;

            return $this->inner->enable($callbackId);
        }

        if (!$timer->enabled) {
            $timer->enabled = true;
            $this->enqueue($timer);
        }

        return $callbackId;
    }

    #[\Override]
    public function cancel(string $callbackId): void
    {
        if (!isset($this->timers[$callbackId])) {
            $this->inner->cancel($callbackId);

            return;
        }

        $this->disable($callbackId);
        unset($this->timers[$callbackId]);
    }

    #[\Override]
    public function disable(string $callbackId): string
    {
        $timer = $this->timers[$callbackId] ?? null;

        if ($timer === null) {
            return $this->inner->disable($callbackId);
        }

        if ($timer->enabled) {
            $timer->enabled = false;

            if ($timer->firingCallbackId !== null) {
                // Its entry is already extracted.
                $this->inner->cancel($timer->firingCallbackId);
                unset($this->own[$timer->firingCallbackId]);
                $timer->firingCallbackId = null;
            } else {
                // Outdates its entry in the queue.
                ++$timer->order;
                ++$this->outdated;
                $this->leave($timer);
                $this->compact();
            }

            $this->updatePump();
        }

        return $callbackId;
    }

    #[\Override]
    public function reference(string $callbackId): string
    {
        $timer = $this->timers[$callbackId] ?? null;

        if ($timer === null) {
            return $this->inner->reference($callbackId);
        }

        if (!$timer->referenced) {
            $timer->referenced = true;

            if ($timer->firingCallbackId !== null) {
                $this->inner->reference($timer->firingCallbackId);
            } elseif ($timer->enabled) {
                ++$this->pendingReferenced;
                $this->updatePump();
            }
        }

        return $callbackId;
    }

    #[\Override]
    public function unreference(string $callbackId): string
    {
        $timer = $this->timers[$callbackId] ?? null;

        if ($timer === null) {
            return $this->inner->unreference($callbackId);
        }

        if ($timer->referenced) {
            $timer->referenced = false;

            if ($timer->firingCallbackId !== null) {
                $this->inner->unreference($timer->firingCallbackId);
            } elseif ($timer->enabled) {
                --$this->pendingReferenced;
                $this->updatePump();
            }
        }

        return $callbackId;
    }

    #[\Override]
    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->inner->setErrorHandler($errorHandler);
    }

    #[\Override]
    public function getErrorHandler(): ?\Closure
    {
        return $this->inner->getErrorHandler();
    }

    #[\Override]
    public function getHandle(): mixed
    {
        return $this->inner->getHandle();
    }

    #[\Override]
    public function getIdentifiers(): array
    {
        $identifiers = array_values(array_filter(
            array: $this->inner->getIdentifiers(),
            callback: fn(string $callbackId) => !isset($this->own[$callbackId]),
        ));

        return [...$identifiers, ...array_keys($this->timers)];
    }

    #[\Override]
    public function getType(string $callbackId): CallbackType
    {
        $timer = $this->timers[$callbackId] ?? null;

        if ($timer === null) {
            return $this->inner->getType($callbackId);
        }

        return $timer->repeat ? CallbackType::Repeat : CallbackType::Delay;
    }

    #[\Override]
    public function isEnabled(string $callbackId): bool
    {
        $timer = $this->timers[$callbackId] ?? null;

        return $timer->enabled ?? $this->inner->isEnabled($callbackId);
    }

    #[\Override]
    public function isReferenced(string $callbackId): bool
    {
        $timer = $this->timers[$callbackId] ?? null;

        return $timer->referenced ?? $this->inner->isReferenced($callbackId);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function __debugInfo(): array
    {
        return [
            'now' => $this->clock->now / 1e9,
            'timers' => \count($this->timers),
            'inner' => $this->inner->__debugInfo(),
        ];
    }

    /**
     * @param \Closure(string): mixed $callback
     */
    private function schedule(float $seconds, \Closure $callback, bool $repeat): string
    {
        if ($seconds < 0) {
            throw new \Error(\sprintf('%s must be greater than or equal to zero', $repeat ? 'Interval' : 'Delay'));
        }

        // Rounded, not truncated: a delay of whole nanoseconds that Revolt passes as float seconds comes back exact.
        // Saturated: a delay of centuries does not fit in int nanoseconds.
        $nanoseconds = round($seconds * 1e9);
        $interval = $nanoseconds >= PHP_INT_MAX ? PHP_INT_MAX : (int) $nanoseconds;
        // Not like the inner ids, which are letters.
        $callbackId = 'virtual-' . $this->nextId++;
        $timer = new VirtualTimer(callbackId: $callbackId, interval: $interval, callback: $callback, repeat: $repeat);
        $this->timers[$callbackId] = $timer;
        $this->enqueue($timer);

        return $callbackId;
    }

    /**
     * Makes the timer pending, due an interval from now; saturated, as a deadline of {@see Scope::after()}.
     */
    private function enqueue(VirtualTimer $timer): void
    {
        $expiration = $this->clock->now + $timer->interval;
        // @phpstan-ignore function.alreadyNarrowedType (an int overflow turns the sum into a float)
        $expiration = \is_int($expiration) ? $expiration : PHP_INT_MAX;
        $timer->expiration = $expiration;
        $timer->order = ++$this->order;
        $this->queue->insert([$expiration, $timer->order, $timer->callbackId]);
        ++$this->pending;

        if ($timer->referenced) {
            ++$this->pendingReferenced;
        }

        $this->updatePump();
    }

    /**
     * The timer is no longer pending: disabled or handed to its firing defer.
     */
    private function leave(VirtualTimer $timer): void
    {
        --$this->pending;

        if ($timer->referenced) {
            --$this->pendingReferenced;
        }
    }

    /**
     * Rebuilds the queue once most of its entries are outdated, so that timers cancelled long before their
     * deadline, as most deadlines are, do not pile up: each rebuild drops more entries than it keeps.
     */
    private function compact(): void
    {
        if ($this->outdated < 64 || $this->outdated <= $this->pending) {
            return;
        }

        /** @var \SplMinHeap<array{int, int, string}> $queue */
        $queue = new \SplMinHeap();

        foreach ($this->queue as $entry) {
            $timer = $this->timers[$entry[2]] ?? null;

            if ($timer !== null && $timer->order === $entry[1]) {
                $queue->insert($entry);
            }
        }

        $this->queue = $queue;
        $this->outdated = 0;
    }

    /**
     * The pump is there while a pending timer is, referenced only if a referenced one is:
     * an unreferenced timer does not keep the loop alive, as with Revolt's own timers.
     */
    private function updatePump(): void
    {
        if ($this->pending === 0) {
            if ($this->pumpCallbackId !== null) {
                $this->inner->cancel($this->pumpCallbackId);
                unset($this->own[$this->pumpCallbackId]);
                $this->pumpCallbackId = null;
            }

            $this->idlePumps = 0;

            return;
        }

        if ($this->pumpCallbackId === null) {
            $this->activityAtPump = $this->activity;
            $this->pumpCallbackId = $this->inner->defer($this->pump(...));
            $this->own[$this->pumpCallbackId] = true;
        }

        if ($this->pendingReferenced > 0) {
            $this->inner->reference($this->pumpCallbackId);
        } else {
            $this->inner->unreference($this->pumpCallbackId);
        }
    }

    private function pump(): void
    {
        \assert($this->pumpCallbackId !== null);
        unset($this->own[$this->pumpCallbackId]);
        $this->pumpCallbackId = null;

        $this->idlePumps = $this->activity === $this->activityAtPump ? $this->idlePumps + 1 : 0;
        $this->activityAtPump = $this->activity;

        // The second idle run in a row: the callbacks found ready with the first one have run since, and none was.
        if ($this->idlePumps >= 2 && ($timer = $this->next()) !== null) {
            // Nothing can run: let the time pass at once.
            $this->clock->jump($timer->expiration);
            $this->idlePumps = 0;
        }

        while (($timer = $this->next()) !== null && $timer->expiration <= $this->clock->now) {
            $this->queue->extract();
            $this->fire($timer);
        }

        $this->updatePump();
    }

    /**
     * The nearest waiting timer, dropping the outdated entries on the way.
     */
    private function next(): ?VirtualTimer
    {
        while (!$this->queue->isEmpty()) {
            [, $order, $callbackId] = $this->queue->top();
            $timer = $this->timers[$callbackId] ?? null;

            if ($timer !== null && $timer->order === $order) {
                return $timer;
            }

            $this->queue->extract();
            --$this->outdated;
        }

        return null;
    }

    private function fire(VirtualTimer $timer): void
    {
        // Its entry is extracted: until the defer runs, the timer is neither due nor pending.
        $this->leave($timer);
        $timer->firingCallbackId = $this->inner->defer(function () use ($timer) {
            \assert($timer->firingCallbackId !== null);
            unset($this->own[$timer->firingCallbackId]);
            $timer->firingCallbackId = null;
            ++$this->activity;

            if ($timer->repeat) {
                $this->enqueue($timer);
            } else {
                unset($this->timers[$timer->callbackId]);
                $this->updatePump();
            }

            // Revolt rejects a callback that returns a value: a timer of this driver is no exception.
            if (($timer->callback)($timer->callbackId) !== null) {
                throw InvalidCallbackError::nonNullReturn($timer->callbackId, $timer->callback);
            }
        });
        $this->own[$timer->firingCallbackId] = true;

        if (!$timer->referenced) {
            $this->inner->unreference($timer->firingCallbackId);
        }
    }

    /**
     * The wrapper returns none, so the inner driver would let a callback that returns a value pass:
     * it is rejected here, as Revolt does. A callback of a stream or a signal also gets the stream or the signal.
     *
     * @param (\Closure(string): mixed)|(\Closure(string, never): mixed) $callback
     * @return \Closure(string, mixed...): void
     */
    private function counted(\Closure $callback): \Closure
    {
        // A callback given is work to do, even before it runs: code that has
        // just started a pump may give one right after.
        ++$this->activity;

        return function (string $callbackId, mixed ...$args) use ($callback) {
            ++$this->activity;

            if ($callback($callbackId, ...$args) !== null) {
                throw InvalidCallbackError::nonNullReturn($callbackId, $callback);
            }
        };
    }
}

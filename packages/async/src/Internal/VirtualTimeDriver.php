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
 * from blocking. Every callback given to this driver counts as activity, when it is given and when it runs;
 * when there has been none since the previous run of the pump, nothing can run, and the clock jumps. So a stream that is to become ready from outside
 * the process may be jumped past. Without a timer there is no pump, and the inner driver blocks on the streams
 * and signals for real. When nothing at all is left, Revolt ends the loop: a deadlock fails at once.
 *
 * A due timer fires through a defer of its own, as Revolt's timers fire as callbacks of their own:
 * the microtasks queued by one, such as a resumed fiber, run before the next one.
 *
 * @internal
 */
final class VirtualTimeDriver implements Driver
{
    /** @var array<string, VirtualTimer> */
    private array $timers = [];

    /**
     * @var \SplMinHeap<array{float, int, string}> [expiration, order, id]; an entry is valid while its order
     * is the timer's: {@see enqueue()} gives a new one, {@see disable()} outdates it. The id, not the timer:
     * an outdated entry stays until it reaches the top, and must not keep the closure of a cancelled timer.
     */
    private readonly \SplMinHeap $queue;

    private int $order = 0;

    private int $nextId = 0;

    /** how many callbacks of the user have been given or have run: the pump compares it between its runs */
    private int $activity = 0;

    private int $activityAtPump = 0;

    private ?string $pump = null;

    /** @var array<string, true> the inner callbacks of this driver: hidden from the identifiers */
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

        $this->inner->queue(function (mixed ...$args) use ($closure): void {
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
        return $this->schedule($delay, $closure, repeat: false);
    }

    #[\Override]
    public function repeat(float $interval, \Closure $closure): string
    {
        return $this->schedule($interval, $closure, repeat: true);
    }

    #[\Override]
    public function onReadable(mixed $stream, \Closure $closure): string
    {
        return $this->inner->onReadable($stream, $this->countedWith($closure));
    }

    #[\Override]
    public function onWritable(mixed $stream, \Closure $closure): string
    {
        return $this->inner->onWritable($stream, $this->countedWith($closure));
    }

    #[\Override]
    public function onSignal(int $signal, \Closure $closure): string
    {
        return $this->inner->onSignal($signal, $this->countedWith($closure));
    }

    #[\Override]
    public function enable(string $callbackId): string
    {
        $timer = $this->timers[$callbackId] ?? null;

        if ($timer === null) {
            return $this->inner->enable($callbackId);
        }

        if (!$timer->enabled) {
            $timer->enabled = true;
            $this->enqueue($timer, $this->clock->now() + $timer->interval);
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
            // Outdates its entry in the queue.
            ++$timer->order;

            if ($timer->firing !== null) {
                $this->inner->cancel($timer->firing);
                unset($this->own[$timer->firing]);
                $timer->firing = null;
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

        $timer->referenced = true;
        $this->updatePump();

        return $callbackId;
    }

    #[\Override]
    public function unreference(string $callbackId): string
    {
        $timer = $this->timers[$callbackId] ?? null;

        if ($timer === null) {
            return $this->inner->unreference($callbackId);
        }

        $timer->referenced = false;
        $this->updatePump();

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
            $this->inner->getIdentifiers(),
            fn(string $callbackId) => !isset($this->own[$callbackId]),
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
        return isset($this->timers[$callbackId]) ? $this->timers[$callbackId]->enabled : $this->inner->isEnabled($callbackId);
    }

    #[\Override]
    public function isReferenced(string $callbackId): bool
    {
        return isset($this->timers[$callbackId]) ? $this->timers[$callbackId]->referenced : $this->inner->isReferenced($callbackId);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function __debugInfo(): array
    {
        return [
            'now' => $this->clock->now(),
            'timers' => \count($this->timers),
            'inner' => $this->inner->__debugInfo(),
        ];
    }

    /**
     * @param \Closure(string): mixed $closure
     */
    private function schedule(float $seconds, \Closure $closure, bool $repeat): string
    {
        if ($seconds < 0) {
            throw new \Error(\sprintf('%s must be greater than or equal to zero', $repeat ? 'Interval' : 'Delay'));
        }

        // Not like the inner ids, which are letters.
        $id = 'virtual-' . $this->nextId++;
        $timer = new VirtualTimer($id, $seconds, $closure, $repeat);
        $this->timers[$id] = $timer;
        $this->enqueue($timer, $this->clock->now() + $seconds);

        return $id;
    }

    private function enqueue(VirtualTimer $timer, float $expiration): void
    {
        $timer->expiration = $expiration;
        $timer->order = ++$this->order;
        $this->queue->insert([$expiration, $timer->order, $timer->id]);
        $this->updatePump();
    }

    /**
     * The pump is there while an enabled timer is, referenced only if a referenced one is:
     * an unreferenced timer does not keep the loop alive, as with Revolt's own timers.
     */
    private function updatePump(): void
    {
        $enabled = false;
        $referenced = false;

        foreach ($this->timers as $timer) {
            if ($timer->enabled && $timer->firing === null) {
                $enabled = true;
                $referenced = $referenced || $timer->referenced;
            }
        }

        if (!$enabled) {
            if ($this->pump !== null) {
                $this->inner->cancel($this->pump);
                unset($this->own[$this->pump]);
                $this->pump = null;
            }

            return;
        }

        if ($this->pump === null) {
            $this->activityAtPump = $this->activity;
            $this->pump = $this->inner->defer($this->pump(...));
            $this->own[$this->pump] = true;
        }

        if ($referenced) {
            $this->inner->reference($this->pump);
        } else {
            $this->inner->unreference($this->pump);
        }
    }

    private function pump(): void
    {
        \assert($this->pump !== null);
        unset($this->own[$this->pump]);
        $this->pump = null;

        $idle = $this->activity === $this->activityAtPump;
        $this->activityAtPump = $this->activity;

        if ($idle && ($timer = $this->next()) !== null) {
            // Nothing has run since the previous pump: nothing can run, let the time pass at once.
            $this->clock->jump($timer->expiration);
        }

        while (($timer = $this->next()) !== null && $timer->expiration <= $this->clock->now()) {
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
            [, $order, $id] = $this->queue->top();
            $timer = $this->timers[$id] ?? null;

            if ($timer !== null && $timer->order === $order) {
                return $timer;
            }

            $this->queue->extract();
        }

        return null;
    }

    private function fire(VirtualTimer $timer): void
    {
        // Its entry is extracted: until the defer runs, the timer is neither due nor pending.
        $timer->firing = $this->inner->defer(function () use ($timer): void {
            \assert($timer->firing !== null);
            unset($this->own[$timer->firing]);
            $timer->firing = null;
            ++$this->activity;

            if ($timer->repeat) {
                $this->enqueue($timer, $this->clock->now() + $timer->interval);
            } else {
                unset($this->timers[$timer->id]);
                $this->updatePump();
            }

            // Revolt rejects a callback that returns a value: a timer of this driver is no exception.
            if (($timer->closure)($timer->id) !== null) {
                throw InvalidCallbackError::nonNullReturn($timer->id, $timer->closure);
            }
        });
        $this->own[$timer->firing] = true;

        if (!$timer->referenced) {
            $this->inner->unreference($timer->firing);
        }
    }

    /**
     * The wrapper returns none, so the inner driver would let a callback that returns a value pass:
     * it is rejected here, as Revolt does.
     *
     * @param \Closure(string): mixed $closure
     * @return \Closure(string): void
     */
    private function counted(\Closure $closure): \Closure
    {
        // A callback given is work to do, even before it runs: code that has just started a pump may give one right after.
        ++$this->activity;

        return function (string $callbackId) use ($closure): void {
            ++$this->activity;

            if ($closure($callbackId) !== null) {
                throw InvalidCallbackError::nonNullReturn($callbackId, $closure);
            }
        };
    }

    /**
     * {@see counted()} for a callback of a stream or a signal.
     *
     * @template T
     * @param \Closure(string, T): mixed $closure
     * @return \Closure(string, T): void
     */
    private function countedWith(\Closure $closure): \Closure
    {
        ++$this->activity;

        return function (string $callbackId, mixed $arg) use ($closure): void {
            ++$this->activity;

            if ($closure($callbackId, $arg) !== null) {
                throw InvalidCallbackError::nonNullReturn($callbackId, $closure);
            }
        };
    }
}

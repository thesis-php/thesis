<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Cancelled;
use Thesis\Async\Continuation;
use Thesis\Async\SignalTrap;
use function Thesis\Async\suspend;

/**
 * @internal
 */
final class SignalTrapImpl implements SignalTrap
{
    /**
     * @var array<int, int> signals received by the process and not yet taken,
     *     keyed by themselves, in the order they came
     */
    private array $pending = [];

    /**
     * @var WaitQueue<Continuation<?int>> waiting receivers; null wakes them at release
     */
    private readonly WaitQueue $receivers;

    private bool $released = false;

    /**
     * The core of `Thesis\Async\trapSignals()`.
     *
     * @template T
     * @param non-empty-array<positive-int> $signals
     * @param callable(SignalTrap): T $fn
     * @return T
     * @throws Cancelled if the scope is cancelled before $fn runs
     * @throws \LogicException without ext-pcntl
     */
    public static function trap(array $signals, callable $fn): mixed
    {
        if (!\function_exists('pcntl_signal')) {
            throw new \LogicException('Signals need ext-pcntl.');
        }

        Scope::current()->throwIfCancelled();
        $trap = new self();
        $handler = $trap->catch(...);
        $loop = Loop::get();
        // A repeated number would get two handlers, and one signal would be received twice.
        $signals = array_unique($signals);

        try {
            foreach ($signals as $signal) {
                $loop->onSignal($signal, $handler);
            }

            return $fn($trap);
        } finally {
            foreach ($signals as $signal) {
                $loop->offSignal($signal, $handler);
            }

            $trap->release();
        }
    }

    private function __construct()
    {
        $this->receivers = new WaitQueue();
    }

    public function receive(): int
    {
        return $this->take()
            ?? $this->wait()
            ?? throw new \LogicException('The signal trap is used after trapSignals() has returned.');
    }

    /**
     * @return \Generator<int, int, void, void>
     */
    public function getIterator(): \Generator
    {
        while (($signal = $this->take() ?? $this->wait()) !== null) {
            yield $signal;
        }
    }

    private function catch(int $signal): void
    {
        // A receiver whose wait is already over (cancelled) refuses it: then it goes to the next one or stays pending.
        while ($this->receivers->items !== [] && ($receiver = $this->receivers->shift()) !== null) {
            if ($receiver->resume($signal)) {
                return;
            }
        }

        $this->pending[$signal] = $signal;
    }

    private function take(): ?int
    {
        foreach ($this->pending as $signal) {
            unset($this->pending[$signal]);

            return $signal;
        }

        return null;
    }

    /**
     * @return ?int null if the trap is released
     */
    private function wait(): ?int
    {
        if ($this->released) {
            return null;
        }

        /** @var ?int */
        return suspend(function (Continuation $continuation) {
            /** @var Continuation<?int> $continuation */
            $key = $this->receivers->push($continuation);

            return function () use ($key) {
                $this->receivers->remove($key);
            };
        });
    }

    private function release(): void
    {
        $this->released = true;

        // A waiting receiver means nothing is pending, so there is nothing left for it.
        foreach ($this->receivers->drain() as $receiver) {
            $receiver->resume(null);
        }
    }
}

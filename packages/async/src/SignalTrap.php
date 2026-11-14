<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;

/**
 * The signals caught by {@see trapSignals()} while its callable runs.
 *
 * Not a queue: like the OS, it keeps at most one pending signal per number, so a signal that comes again
 * before it is received is merged with the pending one, and never pushes out a different signal.
 * Pending signals are received in the order they first came.
 *
 * @api
 * @implements \IteratorAggregate<int, int>
 */
final class SignalTrap implements \IteratorAggregate
{
    /** @var array<int, int> signals received by the process and not yet taken, keyed by themselves, in the order they came */
    private array $pending = [];

    /** @var array<int, Continuation<?int>> waiting receivers, keyed by spl_object_id(); null wakes them at release */
    private array $receivers = [];

    private bool $released = false;

    private function __construct() {}

    /**
     * @internal use {@see trapSignals()}
     * @template T
     * @param non-empty-array<positive-int> $signals
     * @param callable(self): T $fn
     * @return T
     * @throws EventLoop\UnsupportedFeatureException if the driver does not support signals
     */
    public static function trap(array $signals, callable $fn): mixed
    {
        $trap = new self();
        $callback = static function (string $callbackId, int $signal) use ($trap) {
            $trap->catch($signal);
        };
        $callbackIds = [];

        try {
            foreach ($signals as $signal) {
                $callbackIds[] = EventLoop::onSignal($signal, $callback);
            }

            return $fn($trap);
        } finally {
            foreach ($callbackIds as $callbackId) {
                EventLoop::cancel($callbackId);
            }

            $trap->release();
        }
    }

    /**
     * Waits until a signal is caught.
     *
     * @throws \LogicException if {@see trapSignals()} has returned and nothing is pending
     * @throws Cancelled if the scope is cancelled while waiting: then no signal has been taken
     */
    public function receive(): int
    {
        return $this->take() ?? $this->wait() ?? throw new \LogicException('The signal trap is used after trapSignals() has returned.');
    }

    /**
     * Receives the signals as they come, and ends once {@see trapSignals()} has returned and nothing is pending.
     *
     * @return \Generator<int, int, void, void>
     * @throws Cancelled if the scope is cancelled while waiting
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
        foreach ($this->receivers as $id => $receiver) {
            unset($this->receivers[$id]);

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
     * @throws Cancelled
     */
    private function wait(): ?int
    {
        if ($this->released) {
            return null;
        }

        /** @var ?int */
        return suspend(function (Continuation $continuation) {
            /** @var Continuation<?int> $continuation */
            $id = spl_object_id($continuation);
            $this->receivers[$id] = $continuation;

            return function () use ($id) {
                unset($this->receivers[$id]);
            };
        });
    }

    private function release(): void
    {
        $this->released = true;

        // A waiting receiver means nothing is pending, so there is nothing left for it.
        $receivers = $this->receivers;
        $this->receivers = [];

        foreach ($receivers as $receiver) {
            $receiver->resume(null);
        }
    }
}

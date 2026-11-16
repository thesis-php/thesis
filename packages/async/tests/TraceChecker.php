<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\Event;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\Observer;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\ShieldCreated;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Async\Trace\TimeoutCreated;

/**
 * Checks that a trace is whole: a scope is created before anything else happens to it, its waits alternate
 * with their wake-ups, a cancellation reaches only scopes that are still running, a scope ends once, after
 * its waits, and everything created has ended once the observation is over ({@see unfinished()}).
 */
final class TraceChecker implements Observer
{
    /**
     * @var list<string>
     */
    public private(set) array $violations = [];

    /**
     * @var non-negative-int
     */
    public private(set) int $events = 0;

    /**
     * @var array<positive-int, 'running'|'suspended'|'ended'>
     */
    private array $states = [];

    #[\Override]
    public function handle(Event $event): void
    {
        ++$this->events;
        $scope = $event->scope;
        $state = $this->states[$scope] ?? null;

        if (
            $event instanceof GroupCreated
            || $event instanceof TaskCreated
            || $event instanceof TimeoutCreated
            || $event instanceof ShieldCreated
        ) {
            $this->expect($state === null, "#{$scope} is created twice");
            $this->states[$scope] = 'running';

            return;
        }

        $this->expect($state !== null, "#{$scope} is not created before " . $event::class);
        $this->expect(
            $state !== 'ended' || $event instanceof ScopeCancelled,
            "#{$scope} has ended before " . $event::class,
        );

        if ($event instanceof Suspended || $event instanceof TaskStarted) {
            $this->expect($state === 'running', "#{$scope} is {$state} before " . $event::class);
        }

        if ($event instanceof Suspended) {
            $this->states[$scope] = 'suspended';
        } elseif ($event instanceof Resumed) {
            $this->expect($state === 'suspended', "#{$scope} is resumed without a wait");
            $this->states[$scope] = 'running';
        } elseif ($event instanceof Ended) {
            $this->expect($state === 'running', "#{$scope} ends while {$state}");
            $this->states[$scope] = 'ended';
        } elseif ($event instanceof ScopeCancelled) {
            foreach ($event->reached as $reached) {
                $this->expect(($this->states[$reached] ?? 'ended') !== 'ended', "#{$reached} is reached after its end");
            }
        }
    }

    /**
     * @return list<positive-int>
     */
    public function unfinished(): array
    {
        return array_keys(array_filter($this->states, static fn(string $state) => $state !== 'ended'));
    }

    private function expect(bool $condition, string $violation): void
    {
        if (!$condition) {
            $this->violations[] = $violation;
        }
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\Event;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\Joining;
use Thesis\Async\Trace\Observer;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\ShieldCreated;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Async\Trace\TimeoutCreated;
use function Thesis\Time\toNs;

/**
 * Writes the events as lines: "<seconds since the first event> #<scope> <what>".
 */
final class TraceLog implements Observer
{
    /**
     * @var list<string>
     */
    public private(set) array $lines = [];

    /**
     * @var list<Event>
     */
    public private(set) array $events = [];

    private ?Instant $start = null;

    #[\Override]
    public function handle(Event $event): void
    {
        $this->events[] = $event;
        $this->start ??= $event->at;
        $seconds = toNs($event->at->since($this->start)) / 1e9;
        $this->lines[] = \sprintf('%g #%d %s', $seconds, $event->scope, self::describe($event));
    }

    private static function describe(Event $event): string
    {
        return match (true) {
            $event instanceof GroupCreated => 'group in ' . self::scope($event->parent)
                . self::cancelled($event->cancelled),
            $event instanceof TaskCreated => ($event->daemon ? 'daemon' : 'task') . ' in #' . $event->group
                . self::cancelled($event->cancelled),
            $event instanceof TimeoutCreated => \sprintf('timeout %gs in ', toNs($event->duration) / 1e9)
                . self::scope($event->parent) . self::cancelled($event->cancelled),
            $event instanceof ShieldCreated => 'shield in ' . self::scope($event->parent)
                . self::cancelled($event->cancelled),
            $event instanceof TaskStarted => 'started',
            $event instanceof Suspended => 'suspended in ' . ($event->caller->callee ?? '?'),
            $event instanceof Resumed => $event->error === null
                ? 'resumed'
                : 'resumed by ' . self::error($event->error),
            $event instanceof ScopeCancelled => \sprintf(
                'cancelled: %s by %s, reached %s',
                $event->cause->name,
                self::scope($event->by),
                implode(' ', array_map(static fn(int $scope) => '#' . $scope, $event->reached)),
            ),
            $event instanceof Joining => 'joining',
            $event instanceof Ended => $event->error === null
                ? 'returned ' . json_encode($event->value)
                : 'threw ' . self::error($event->error),
            default => $event::class,
        };
    }

    private static function scope(?int $scope): string
    {
        return $scope === null ? 'root' : '#' . $scope;
    }

    private static function cancelled(bool $cancelled): string
    {
        return $cancelled ? ', cancelled' : '';
    }

    private static function error(\Throwable $error): string
    {
        return new \ReflectionClass($error)->getShortName();
    }
}

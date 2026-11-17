<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Instant;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\Event;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\Joining;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\ShieldCreated;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Async\Trace\TimeoutCreated;
use Thesis\Dispatcher\Listener;
use Time\Duration;
use function Thesis\Formatter\formatValue;

/**
 * Keeps every event of `Thesis\Async\Trace` as a line of text, `<seconds> #<scope> <what>`:
 * `1 #2 suspended in Thesis\Async\delay`. On virtual time the lines are the same on every run,
 * so a test compares them with what it expects. A method per event: `new Dispatcher(listenersOf($log))`.
 *
 * The lines stay in memory, it is for a test or a short program, not for a process that lives long.
 *
 * @api
 */
final class TextLog
{
    private const string ASYNC_NAMESPACE = 'Thesis\Async\\';

    /**
     * @var list<non-empty-string>
     */
    public private(set) array $lines = [];

    /**
     * The first event: the time of every line is the seconds since it.
     */
    private ?Instant $origin = null;

    #[Listener]
    public function onGroupCreated(GroupCreated $event): void
    {
        $this->add(
            event: $event,
            what: str_replace(self::ASYNC_NAMESPACE, '', $event->function)
            . self::name($event->name)
            . ' in '
            . self::scope($event->parent)
            . self::cancelled($event->cancelled),
        );
    }

    #[Listener]
    public function onTaskCreated(TaskCreated $event): void
    {
        $this->add(
            event: $event,
            what: ($event->daemon ? 'daemon' : 'task')
            . self::name($event->name)
            . ' in #'
            . $event->group
            . self::cancelled($event->cancelled),
        );
    }

    #[Listener]
    public function onTimeoutCreated(TimeoutCreated $event): void
    {
        $this->add(
            event: $event,
            what: 'timeout '
            . self::seconds($event->duration)
            . 's in '
            . self::scope($event->parent)
            . self::cancelled($event->cancelled),
        );
    }

    #[Listener]
    public function onShieldCreated(ShieldCreated $event): void
    {
        $this->add(
            event: $event,
            what: 'shield in '
            . self::scope($event->parent)
            . self::cancelled($event->cancelled),
        );
    }

    #[Listener]
    public function onTaskStarted(TaskStarted $event): void
    {
        $this->add($event, 'started');
    }

    #[Listener]
    public function onSuspended(Suspended $event): void
    {
        $this->add($event, $event->caller === null ? 'suspended' : 'suspended in ' . $event->caller->callee);
    }

    #[Listener]
    public function onResumed(Resumed $event): void
    {
        $this->add($event, $event->error === null ? 'resumed' : 'resumed by ' . self::error($event->error));
    }

    #[Listener]
    public function onScopeCancelled(ScopeCancelled $event): void
    {
        $this->add($event, \sprintf(
            'cancelled: %s by %s, reached %s',
            $event->cause->name,
            self::scope($event->by),
            implode(' ', array_map(static fn(int $scope) => '#' . $scope, $event->reached)),
        ));
    }

    #[Listener]
    public function onJoining(Joining $event): void
    {
        $this->add($event, 'joining');
    }

    #[Listener]
    public function onEnded(Ended $event): void
    {
        $this->add($event, $event->error === null
            ? 'returned ' . formatValue($event->value)
            : 'threw ' . self::error($event->error));
    }

    /**
     * The exact decimal of the seconds, without trailing zeros: `0`, `1.5`, `1.000000001`, `-1.5`.
     *
     * @return non-empty-string
     */
    private static function seconds(Duration $duration): string
    {
        $sign = $duration->negative ? '-' : '';

        if ($duration->nanoseconds === 0) {
            return $sign . $duration->seconds;
        }

        return $sign . $duration->seconds . '.' . rtrim(\sprintf('%09d', $duration->nanoseconds), '0');
    }

    private static function name(?string $name): string
    {
        return $name === null ? '' : ' ' . formatValue($name);
    }

    /**
     * @return non-empty-string
     */
    private static function scope(?int $scope): string
    {
        return $scope === null ? 'root' : '#' . $scope;
    }

    private static function cancelled(bool $cancelled): string
    {
        return $cancelled ? ', cancelled' : '';
    }

    /**
     * @return non-empty-string
     */
    private static function error(\Throwable $error): string
    {
        return new \ReflectionClass($error)->getShortName();
    }

    /**
     * @param non-empty-string $what
     */
    private function add(Event $event, string $what): void
    {
        $this->origin ??= $event->at;
        $seconds = self::seconds($event->at->since($this->origin));
        $this->lines[] = \sprintf('%s #%d %s', $seconds, $event->scope, $what);
    }
}

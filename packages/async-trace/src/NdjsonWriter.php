<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Psr\Clock\ClockInterface;
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
use Thesis\SourceLocation\Caller;
use Thesis\SourceLocation\Location;
use Thesis\Time\WallClock;
use function Thesis\Formatter\formatFunction;
use function Thesis\Formatter\formatValue;
use function Thesis\Time\toNs;

/**
 * Writes every event of `Thesis\Async\Trace` to a stream as a line of JSON: `new Dispatcher(listenersOf($writer))`.
 * A header line `{"format":"thesis/async-trace","version":1,"start":...}` goes before the first event.
 * The values, the errors and the tasks are formatted at once, nothing of an event is kept.
 *
 * It writes synchronously, as a listener of `observe()` must: the stream is blocking (a file, `php://stderr`),
 * a non-blocking one loses what it cannot take at once. A line it could not write is counted in {@see $lost},
 * a listener must not throw.
 *
 * @api
 */
final class NdjsonWriter
{
    public const int VERSION = 1;
    private const int FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * The lines it could not write, the header included.
     *
     * @var non-negative-int
     */
    public private(set) int $lost = 0;

    /**
     * The first event: `at` of every line is the nanoseconds since it.
     */
    private ?Instant $origin = null;

    /**
     * @param resource $stream a blocking stream open for writing
     * @param ClockInterface $clock the wall clock of `start` in the header: the time of the first event
     */
    public function __construct(
        private readonly mixed $stream,
        private readonly ClockInterface $clock = new WallClock(),
    ) {}

    #[Listener]
    public function onGroupCreated(GroupCreated $event): void
    {
        $this->write($event, 'GroupCreated', [
            'parent' => $event->parent,
            'function' => $event->function,
            'name' => $event->name,
            'cancelled' => $event->cancelled,
            'location' => self::location($event->location),
        ]);
    }

    #[Listener]
    public function onTaskCreated(TaskCreated $event): void
    {
        $this->write($event, 'TaskCreated', [
            'group' => $event->group,
            'daemon' => $event->daemon,
            'name' => $event->name,
            'task' => formatFunction($event->task),
            'cancelled' => $event->cancelled,
            'location' => self::location($event->location),
        ]);
    }

    #[Listener]
    public function onTimeoutCreated(TimeoutCreated $event): void
    {
        $this->write($event, 'TimeoutCreated', [
            'parent' => $event->parent,
            'duration' => toNs($event->duration),
            'cancelled' => $event->cancelled,
            'location' => self::location($event->location),
        ]);
    }

    #[Listener]
    public function onShieldCreated(ShieldCreated $event): void
    {
        $this->write($event, 'ShieldCreated', [
            'parent' => $event->parent,
            'cancelled' => $event->cancelled,
            'location' => self::location($event->location),
        ]);
    }

    #[Listener]
    public function onTaskStarted(TaskStarted $event): void
    {
        $this->write($event, 'TaskStarted', []);
    }

    #[Listener]
    public function onSuspended(Suspended $event): void
    {
        $this->write($event, 'Suspended', ['caller' => self::caller($event->caller)]);
    }

    #[Listener]
    public function onResumed(Resumed $event): void
    {
        $this->write($event, 'Resumed', ['error' => self::error($event->error)]);
    }

    #[Listener]
    public function onScopeCancelled(ScopeCancelled $event): void
    {
        $this->write($event, 'ScopeCancelled', [
            'cause' => $event->cause->name,
            'by' => $event->by,
            'reached' => $event->reached,
            'caller' => self::caller($event->caller),
            'error' => self::error($event->error),
        ]);
    }

    #[Listener]
    public function onJoining(Joining $event): void
    {
        $this->write($event, 'Joining', []);
    }

    #[Listener]
    public function onEnded(Ended $event): void
    {
        $this->write($event, 'Ended', [
            'value' => $event->error === null ? formatValue($event->value) : null,
            'error' => self::error($event->error),
        ]);
    }

    /**
     * @return ?array{file: non-empty-string, line: positive-int}
     */
    private static function location(?Location $location): ?array
    {
        return $location === null ? null : ['file' => $location->file, 'line' => $location->line];
    }

    /**
     * @return ?array{callee: non-empty-string, file: non-empty-string, line: positive-int}
     */
    private static function caller(?Caller $caller): ?array
    {
        if ($caller === null) {
            return null;
        }

        return ['callee' => $caller->callee, 'file' => $caller->location->file, 'line' => $caller->location->line];
    }

    /**
     * @return ?array{class: class-string<\Throwable>, message: string, file: string, line: int}
     */
    private static function error(?\Throwable $error): ?array
    {
        if ($error === null) {
            return null;
        }

        return [
            'class' => $error::class,
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
    }

    /**
     * @param non-empty-string $type
     * @param array<non-empty-string, mixed> $fields
     */
    private function write(Event $event, string $type, array $fields): void
    {
        $origin = $this->origin;

        if ($origin === null) {
            $origin = $this->origin = $event->at;
            $this->writeLine([
                'format' => 'thesis/async-trace',
                'version' => self::VERSION,
                'start' => $this->clock->now()->format('Y-m-d\TH:i:s.uP'),
            ]);
        }

        $this->writeLine([
            'event' => $type,
            'scope' => $event->scope,
            'at' => toNs($event->at->since($origin)),
            ...$fields,
        ]);
    }

    /**
     * @param array<non-empty-string, mixed> $record
     */
    private function writeLine(array $record): void
    {
        $line = json_encode($record, self::FLAGS) . "\n";
        $length = \strlen($line);
        $written = 0;

        while ($written < $length) {
            // A failure is counted, not reported: the warning would go to the error handler of the program.
            $sent = @fwrite($this->stream, substr($line, $written));

            if ($sent === false || $sent === 0) {
                ++$this->lost;

                return;
            }

            $written += $sent;
        }
    }
}

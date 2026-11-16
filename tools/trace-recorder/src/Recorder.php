<?php

declare(strict_types=1);

namespace Thesis\TraceRecorder;

use Thesis\Async\Cancelled;
use Thesis\Async\Instant;
use Thesis\Async\Trace\CancelCause;
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
use Thesis\Dispatcher;
use function Thesis\Async\Trace\observe;
use function Thesis\Async\VirtualTime\run;
use function Thesis\Formatter\formatValue;
use function Thesis\Time\toNs;

/**
 * Records a program on virtual time into the trace of the demos of the documentation site:
 * a row per group, task, daemon, timeout and shield, and the events in their order.
 * Times are milliseconds since the first event.
 *
 * @phpstan-type Row array{
 *     label: non-empty-string,
 *     scope: non-empty-string,
 *     parent: ?non-empty-string,
 *     start: null|int|float,
 *     end: null|int|float,
 *     cancelAt: null|int|float,
 *     outcome: null|'value'|'error'|'cancelled',
 * }
 * @phpstan-type TraceEvent array{t: int|float, text: non-empty-string, kind: non-empty-string, scope: non-empty-string}
 */
final class Recorder
{
    /**
     * @var array<positive-int, Row>
     */
    private array $rows = [];

    /**
     * @var list<TraceEvent>
     */
    private array $events = [];

    private ?Instant $start = null;

    /**
     * @param callable(): mixed $program
     * @return array{rows: list<Row>, events: list<TraceEvent>}
     */
    public static function record(callable $program): array
    {
        $recorder = new self();
        run(static fn() => observe(new Dispatcher([$recorder]), $program));

        return ['rows' => array_values($recorder->rows), 'events' => $recorder->events];
    }

    private function __construct() {}

    public function __invoke(Event $event): void
    {
        $this->start ??= $event->at;
        $t = toNs($event->at->since($this->start)) / 1e6;
        $t = $t === floor($t) ? (int) $t : $t;
        $scope = $event->scope;

        [$kind, $text] = match (true) {
            $event instanceof GroupCreated => $this->created(
                scope: $scope,
                label: $event->name ?? self::shortName($event->function),
                parent: $event->parent,
                cancelled: $event->cancelled,
                t: $t,
            ),
            $event instanceof TaskCreated => $this->created(
                scope: $scope,
                label: $event->name ?? ($event->daemon ? 'daemon' : 'task') . ' #' . $scope,
                parent: $event->group,
                cancelled: $event->cancelled,
                t: null,
            ),
            $event instanceof TimeoutCreated => $this->created(
                scope: $scope,
                label: \sprintf('timeout %gs', toNs($event->duration) / 1e9),
                parent: $event->parent,
                cancelled: $event->cancelled,
                t: $t,
            ),
            $event instanceof ShieldCreated => $this->created($scope, 'shield', $event->parent, $event->cancelled, $t),
            $event instanceof TaskStarted => $this->started($scope, $t),
            $event instanceof Suspended => [
                'suspend',
                \sprintf(
                    '%s waits in %s()',
                    $this->name($scope),
                    self::shortName($event->caller->callee ?? 'suspend'),
                ),
            ],
            $event instanceof Resumed => ['resume', $this->name($scope) . match (true) {
                $event->error === null => ' wakes up',
                $event->error instanceof Cancelled => ' wakes up cancelled',
                default => ' wakes up to ' . self::error($event->error),
            }],
            $event instanceof ScopeCancelled => $this->cancelled($event, $t),
            $event instanceof Joining => ['join', $this->name($scope) . ' waits for its tasks'],
            $event instanceof Ended => $this->ended($scope, $event->value, $event->error, $t),
            default => ['other', $event::class],
        };

        $this->events[] = ['t' => $t, 'text' => $text, 'kind' => $kind, 'scope' => 's' . $scope];
    }

    /**
     * @param positive-int $scope
     * @param non-empty-string $label
     * @param ?positive-int $parent
     * @param null|int|float $t null for a task: it starts later, if it does
     * @return array{non-empty-string, non-empty-string}
     */
    private function created(int $scope, string $label, ?int $parent, bool $cancelled, null|int|float $t): array
    {
        $this->rows[$scope] = [
            'label' => $label,
            'scope' => 's' . $scope,
            'parent' => $parent === null ? null : 's' . $parent,
            'start' => $t,
            'end' => null,
            'cancelAt' => $cancelled ? $t : null,
            'outcome' => null,
        ];

        $in = $parent === null ? '' : ' in ' . $this->name($parent);

        return ['create', $label . ' is created' . $in . ($cancelled ? ', already cancelled' : '')];
    }

    /**
     * @param positive-int $scope
     * @return array{non-empty-string, non-empty-string}
     */
    private function started(int $scope, int|float $t): array
    {
        if (isset($this->rows[$scope])) {
            $this->rows[$scope]['start'] = $t;
        }

        return ['start', $this->name($scope) . ' starts'];
    }

    /**
     * @return array{non-empty-string, non-empty-string}
     */
    private function cancelled(ScopeCancelled $event, int|float $t): array
    {
        foreach ($event->reached as $reached) {
            if (isset($this->rows[$reached])) {
                $this->rows[$reached]['cancelAt'] = $t;
            }
        }

        $scope = $this->name($event->scope);
        $by = $event->by === null ? 'the event loop' : $this->name($event->by);
        $text = match ($event->cause) {
            CancelCause::Handle => "{$by} cancels {$scope}",
            CancelCause::CancelTasks => "{$by} cancels the tasks of {$scope}",
            CancelCause::Failure => "the failure of {$by} cancels {$scope}",
            CancelCause::Deadline => "the deadline of {$scope} passes",
            CancelCause::DaemonsAfterTasks => "{$scope} cancels its daemons: its tasks are done",
        };
        $reached = array_map($this->name(...), $event->reached);

        return ['cancel', $text . ($reached === [] ? '' : ', reaching ' . implode(', ', $reached))];
    }

    /**
     * Formatted at once: the recorder must not hold the value alive past its scope.
     *
     * @param positive-int $scope
     * @return array{non-empty-string, non-empty-string}
     */
    private function ended(int $scope, mixed $value, ?\Throwable $error, int|float $t): array
    {
        $outcome = match (true) {
            $error === null => 'value',
            $error instanceof Cancelled => 'cancelled',
            default => 'error',
        };

        if (isset($this->rows[$scope])) {
            $this->rows[$scope]['end'] = $t;
            $this->rows[$scope]['outcome'] = $outcome;
        }

        $label = $this->name($scope);

        return ['end', match ($outcome) {
            'value' => $label . ' returns ' . formatValue($value),
            'cancelled' => $label . ' ends cancelled',
            'error' => $label . ' throws ' . self::error($error),
        }];
    }

    /**
     * @param positive-int $scope
     * @return non-empty-string
     */
    private function name(int $scope): string
    {
        return $this->rows[$scope]['label'] ?? '#' . $scope;
    }

    /**
     * @return non-empty-string
     */
    private static function error(?\Throwable $error): string
    {
        if ($error === null) {
            return 'nothing';
        }

        $class = new \ReflectionClass($error)->getShortName();

        return $error->getMessage() === '' ? $class : $class . ': ' . $error->getMessage();
    }

    /**
     * A function of thesis/async without its namespace: `race`, `Task::await`.
     *
     * @param non-empty-string $name
     * @return non-empty-string
     */
    private static function shortName(string $name): string
    {
        /** @var non-empty-string */
        return str_replace('Thesis\Async\\', '', $name);
    }
}

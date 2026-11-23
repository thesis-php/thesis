<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Instant;
use Thesis\Async\Trace\CancelCause;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\Event;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\Joining;
use Thesis\Async\Trace\ObserverDetached;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\ShieldCreated;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Async\Trace\TimeoutCreated;
use Thesis\Dispatcher;
use Thesis\SourceLocation\Caller;
use Thesis\SourceLocation\Location;
use Time\Duration;
use function Thesis\SourceLocation\caller;

/**
 * Turns what happens to the scopes into the events of `Thesis\Async\Trace\observe()`. Bound to {@see Scope::$tracer}
 * only while a dispatcher is attached: every hook of the library is `Scope::$tracer?->...`, so without one
 * a hook costs a null check and does not evaluate its arguments. An event that no listener gets is not built:
 * the location of a scope and the caller of a wait cost a backtrace each.
 *
 * A scope is numbered when the tracer sees it first, whether anyone gets its event or not: the numbers
 * do not depend on what is listened to. The tasks and the daemons scopes of a group are not
 * scopes of the model: they get the number of the body, whose group owns them.
 *
 * @internal
 */
final class Tracer
{
    /**
     * Frames to look through for the call from outside: enough for a task of the library alone (a wait passed
     * to `spawn()` as is), whose stack goes on past the loop to the call that runs it.
     */
    private const int FRAMES = 32;

    public static function attach(Dispatcher $dispatcher): self
    {
        $tracer = Scope::$tracer ??= new self();
        $tracer->dispatchers[] = $dispatcher;
        $tracer->listens = [];

        return $tracer;
    }

    /**
     * @var list<Dispatcher>
     */
    private array $dispatchers = [];

    /**
     * Whether any attached dispatcher listens to an event class, reset when the dispatchers change.
     *
     * @var array<class-string<Event>, bool>
     */
    private array $listens = [];

    /**
     * @var \WeakMap<Scope, positive-int>
     */
    private readonly \WeakMap $ids;

    /**
     * The scopes of the model that have neither ended nor been cancelled: a cancellation reports
     * which of them it has reached, without walking the tree again.
     *
     * @var \WeakMap<Scope, positive-int>
     */
    private readonly \WeakMap $pending;

    /**
     * The outcomes of the tasks whose wrapper has caught them ({@see settled()}), until the tasks end.
     *
     * @var \WeakMap<Scope, array{mixed, ?\Throwable}>
     */
    private readonly \WeakMap $outcomes;

    /**
     * @var non-negative-int
     */
    private int $lastId = 0;

    private function __construct()
    {
        $this->ids = new \WeakMap();
        $this->pending = new \WeakMap();
        $this->outcomes = new \WeakMap();
    }

    /**
     * Observations may end in any order: their calls run in different fibers.
     */
    public function detach(Dispatcher $dispatcher): void
    {
        $index = array_search($dispatcher, $this->dispatchers, strict: true);

        if ($index !== false) {
            array_splice($this->dispatchers, $index, 1);
            $this->listens = [];
        }

        // The next observation numbers its scopes from 1 again. Only the last detach of this tracer resets it:
        // a new one is created only once the dispatchers are gone, so an older tracer never unbinds a newer one.
        if ($this->dispatchers === [] && Scope::$tracer === $this) {
            Scope::$tracer = null;
        }

        if (!\in_array($dispatcher, $this->dispatchers, true) && $dispatcher->listens(ObserverDetached::class)) {
            $dispatcher->dispatch(new ObserverDetached());
        }
    }

    /**
     * @param non-empty-string $function
     * @param ?non-empty-string $name
     */
    public function groupCreated(
        Scope $body,
        Scope $parent,
        string $function,
        ?string $name,
    ): void {
        $id = $this->created($body, $parent);

        if ($this->listens(GroupCreated::class)) {
            $this->emit(new GroupCreated(
                scope: $id,
                at: Instant::now(),
                parent: $this->idOrNull($parent),
                function: $function,
                name: $name,
                cancelled: $body->isCancelled,
                location: self::location(),
            ));
        }
    }

    /**
     * @param ?non-empty-string $name
     * @param callable(never): mixed $task
     */
    public function taskCreated(Scope $scope, Scope $body, bool $daemon, ?string $name, callable $task): void
    {
        $id = $this->created($scope, $body);

        if ($this->listens(TaskCreated::class)) {
            $this->emit(new TaskCreated(
                scope: $id,
                at: Instant::now(),
                group: $this->id($body),
                daemon: $daemon,
                name: $name,
                task: $task,
                cancelled: $scope->isCancelled,
                location: self::location(),
            ));
        }
    }

    public function timeoutCreated(Scope $scope, Scope $parent, Duration $duration): void
    {
        $id = $this->created($scope, $parent);

        if ($this->listens(TimeoutCreated::class)) {
            $this->emit(new TimeoutCreated(
                scope: $id,
                at: Instant::now(),
                parent: $this->idOrNull($parent),
                duration: $duration,
                cancelled: $scope->isCancelled,
                location: self::location(),
            ));
        }
    }

    public function shieldCreated(Scope $scope, Scope $parent): void
    {
        $id = $this->created($scope, $parent);

        if ($this->listens(ShieldCreated::class)) {
            $this->emit(new ShieldCreated(
                scope: $id,
                at: Instant::now(),
                parent: $this->idOrNull($parent),
                cancelled: $scope->isCancelled,
                location: self::location(),
            ));
        }
    }

    public function taskStarted(Scope $scope): void
    {
        $id = $this->id($scope);

        if ($this->listens(TaskStarted::class)) {
            $this->emit(new TaskStarted($id, Instant::now()));
        }
    }

    public function suspended(Scope $scope): void
    {
        $id = $this->idOrNull($scope);

        if ($id !== null && $this->listens(Suspended::class)) {
            $this->emit(new Suspended($id, Instant::now(), self::publicCaller()));
        }
    }

    public function resumed(Scope $scope, ?\Throwable $error): void
    {
        $id = $this->idOrNull($scope);

        if ($id !== null && $this->listens(Resumed::class)) {
            $this->emit(new Resumed($id, Instant::now(), $error));
        }
    }

    /**
     * Called after {@see Scope::notify()} has marked the subtree: no listener runs in the middle of the walk.
     */
    public function cancelled(Scope $scope, CancelCause $cause, ?Scope $by, ?\Throwable $error): void
    {
        $reached = [];
        $cancelled = [];

        foreach ($this->pending as $pending => $id) {
            if ($pending->isCancelled) {
                $reached[] = $id;
                $cancelled[] = $pending;
            }
        }

        // Not while iterating: a WeakMap changed in a foreach over it may skip an entry.
        foreach ($cancelled as $pending) {
            unset($this->pending[$pending]);
        }

        $id = $this->id($scope);
        $byId = $by === null ? null : $this->idOrNull($by);

        if ($this->listens(ScopeCancelled::class)) {
            $this->emit(new ScopeCancelled(
                scope: $id,
                at: Instant::now(),
                cause: $cause,
                by: $byId,
                reached: $reached,
                caller: match ($cause) {
                    CancelCause::Handle, CancelCause::CancelTasks => self::publicCaller(),
                    CancelCause::Failure, CancelCause::Deadline, CancelCause::DaemonsAfterTasks => null,
                },
                error: $error,
            ));
        }
    }

    public function joining(Scope $body): void
    {
        $id = $this->id($body);

        if ($this->listens(Joining::class)) {
            $this->emit(new Joining($id, Instant::now()));
        }
    }

    /**
     * The outcome of the callable of a task of `allSettled()`, `some()` or `race()`: their wrapper catches it,
     * so the task itself ends with what the wrapper returns. {@see Ended()} reports this one instead.
     */
    public function settled(Scope $scope, mixed $value, ?\Throwable $error): void
    {
        // Kept until the task ends: not a moment longer, and not at all if nobody gets it.
        if ($this->listens(Ended::class)) {
            $this->outcomes[$scope] = [$value, $error];
        }
    }

    public function ended(Scope $scope, mixed $value, ?\Throwable $error): void
    {
        unset($this->pending[$scope]);
        $id = $this->id($scope);

        if (isset($this->outcomes[$scope])) {
            [$value, $error] = $this->outcomes[$scope];
            unset($this->outcomes[$scope]);
        }

        if ($this->listens(Ended::class)) {
            $this->emit(new Ended($id, Instant::now(), $value, $error));
        }
    }

    /**
     * @return positive-int
     */
    private function created(Scope $scope, Scope $parent): int
    {
        // The parent first: a scope the observation has not seen yet gets the smaller number, as the older one.
        $this->idOrNull($parent);
        $id = $this->id($scope);

        if (!$scope->isCancelled) {
            $this->pending[$scope] = $id;
        }

        return $id;
    }

    /**
     * @return positive-int
     */
    private function id(Scope $scope): int
    {
        return $this->ids[$scope] ??= ++$this->lastId;
    }

    /**
     * @return ?positive-int null for the root scope
     */
    private function idOrNull(Scope $scope): ?int
    {
        return $scope === ROOT_SCOPE ? null : $this->id($scope);
    }

    /**
     * @param class-string<Event> $class
     */
    private function listens(string $class): bool
    {
        // Only saves the work of asking the dispatchers again, and building an event nobody gets is only slower.
        // @infection-ignore ArrayAny, AssignCoalesce
        return $this->listens[$class] ??= array_any(
            $this->dispatchers,
            static fn(Dispatcher $dispatcher) => $dispatcher->listens($class),
        );
    }

    private function emit(Event $event): void
    {
        foreach ($this->dispatchers as $dispatcher) {
            $dispatcher->dispatch($event);
        }
    }

    private static function location(): ?Location
    {
        return caller(self::libraries(), self::FRAMES)?->location;
    }

    /**
     * The call of the library from outside, named by the public API: a task handle is `Thesis\Async\Task`,
     * not its implementation.
     */
    private static function publicCaller(): ?Caller
    {
        $caller = caller(self::libraries(), self::FRAMES);
        $implementation = '/^Thesis\\\Async\\\Internal\\\(\w+)Impl::(.+)$/D';

        if ($caller === null || preg_match($implementation, $caller->callee, $matches) !== 1) {
            return $caller;
        }

        return new Caller($caller->location, 'Thesis\Async\\' . $matches[1] . '::' . $matches[2]);
    }

    /**
     * This library, the event loop among it: a task runs in a fiber that the loop starts.
     *
     * @return non-empty-list<non-empty-string>
     */
    private static function libraries(): array
    {
        return [\dirname(__DIR__)];
    }
}

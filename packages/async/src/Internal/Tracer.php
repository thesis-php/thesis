<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Thesis\Async\Instant;
use Thesis\Async\Trace\CancelCause;
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
use Thesis\SourceLocation\Caller;
use Thesis\SourceLocation\Location;
use Time\Duration;
use function Thesis\SourceLocation\caller;

/**
 * Turns what happens to the scopes into the events of `Thesis\Async\Trace\observe()`. Bound to {@see Scope::$tracer}
 * only while an observer is attached: every hook of the library is `Scope::$tracer?->...`, so without one
 * a hook costs a null check and does not evaluate its arguments.
 *
 * A scope is numbered when the tracer sees it first. The tasks and the daemons scopes of a group are not
 * scopes of the model: they get the number of the body, whose group owns them.
 *
 * @internal
 */
final class Tracer
{
    /**
     * @var list<Observer>
     */
    private array $observers = [];

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
     * @var non-negative-int
     */
    private int $lastId = 0;

    public static function attach(Observer $observer): self
    {
        $tracer = Scope::$tracer ??= new self();
        $tracer->observers[] = $observer;

        return $tracer;
    }

    private function __construct()
    {
        $this->ids = new \WeakMap();
        $this->pending = new \WeakMap();
    }

    /**
     * Observations may end in any order: their calls run in different fibers.
     */
    public function detach(Observer $observer): void
    {
        $index = array_search($observer, $this->observers, strict: true);

        if ($index !== false) {
            array_splice($this->observers, $index, 1);
        }

        // The next observation numbers its scopes from 1 again.
        if ($this->observers === [] && Scope::$tracer === $this) {
            Scope::$tracer = null;
        }
    }

    public function groupCreated(Scope $body, Scope $tasks, Scope $daemons, Scope $parent): void
    {
        $event = new GroupCreated(
            scope: $this->created($body, $parent),
            at: Instant::now(),
            parent: $this->idOrNull($parent),
            cancelled: $body->isCancelled(),
            location: self::location(),
        );
        $this->ids[$tasks] = $event->scope;
        $this->ids[$daemons] = $event->scope;
        $this->emit($event);
    }

    /**
     * @param callable(): mixed $task
     */
    public function taskCreated(Scope $scope, Scope $body, bool $daemon, callable $task): void
    {
        $this->emit(new TaskCreated(
            scope: $this->created($scope, $body),
            at: Instant::now(),
            group: $this->id($body),
            daemon: $daemon,
            task: $task,
            cancelled: $scope->isCancelled(),
            location: self::location(),
        ));
    }

    public function timeoutCreated(Scope $scope, Scope $parent, Duration $duration): void
    {
        $this->emit(new TimeoutCreated(
            scope: $this->created($scope, $parent),
            at: Instant::now(),
            parent: $this->idOrNull($parent),
            duration: $duration,
            cancelled: $scope->isCancelled(),
            location: self::location(),
        ));
    }

    public function shieldCreated(Scope $scope, Scope $parent): void
    {
        $this->emit(new ShieldCreated(
            scope: $this->created($scope, $parent),
            at: Instant::now(),
            parent: $this->idOrNull($parent),
            cancelled: $scope->isCancelled(),
            location: self::location(),
        ));
    }

    public function taskStarted(Scope $scope): void
    {
        $this->emit(new TaskStarted($this->id($scope), Instant::now()));
    }

    /**
     * The wait of `Thesis\Async\suspend()` once the continuation is subscribed: it is the same as there,
     * with the events around {@see ContinuationImpl::park()}. The root scope is not a scope of the model,
     * its waits are not reported.
     *
     * @template T
     * @param ContinuationImpl<T> $continuation
     * @return T
     */
    public function park(Scope $scope, ContinuationImpl $continuation, mixed $unregister): mixed
    {
        $id = $this->idOrNull($scope);

        if ($id !== null) {
            $this->emit(new Suspended($id, Instant::now(), self::waitingCaller()));
        }

        $value = null;
        $error = null;

        try {
            $value = $continuation->park();
        } catch (\Throwable $error) {
        } finally {
            $scope->unsubscribe($continuation);

            if ($unregister instanceof \Closure) {
                $unregister();
            }
        }

        if ($id !== null) {
            $this->emit(new Resumed($id, Instant::now(), $error));
        }

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }

    /**
     * Called after {@see Scope::notify()} has marked the subtree: the observer never runs in the middle of the walk.
     */
    public function cancelled(Scope $scope, CancelCause $cause, ?Scope $by): void
    {
        $reached = [];
        $cancelled = [];

        foreach ($this->pending as $pending => $id) {
            if ($pending->isCancelled()) {
                $reached[] = $id;
                $cancelled[] = $pending;
            }
        }

        // Not while iterating: a WeakMap changed in a foreach over it may skip an entry.
        foreach ($cancelled as $pending) {
            unset($this->pending[$pending]);
        }

        $this->emit(new ScopeCancelled(
            scope: $this->id($scope),
            at: Instant::now(),
            cause: $cause,
            by: $by === null ? null : $this->idOrNull($by),
            reached: $reached,
        ));
    }

    public function joining(Scope $body): void
    {
        $this->emit(new Joining($this->id($body), Instant::now()));
    }

    public function ended(Scope $scope, mixed $value, ?\Throwable $error): void
    {
        unset($this->pending[$scope]);
        $this->emit(new Ended($this->id($scope), Instant::now(), $value, $error));
    }

    /**
     * @return positive-int
     */
    private function created(Scope $scope, Scope $parent): int
    {
        // The parent first: a scope the observation has not seen yet gets the smaller number, as the older one.
        $this->idOrNull($parent);
        $id = $this->id($scope);

        if (!$scope->isCancelled()) {
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

    private function emit(Event $event): void
    {
        foreach ($this->observers as $observer) {
            $observer->handle($event);
        }
    }

    private static function location(): ?Location
    {
        return caller(self::libraries())?->location;
    }

    /**
     * The call that waits, named by the public API: a task handle is `Thesis\Async\Task`, not its implementation.
     */
    private static function waitingCaller(): ?Caller
    {
        $caller = caller(self::libraries());
        $implementation = '/^Thesis\\\Async\\\Internal\\\(\w+)Impl::(.+)$/D';

        if ($caller === null || preg_match($implementation, $caller->callee, $matches) !== 1) {
            return $caller;
        }

        return new Caller($caller->location, 'Thesis\Async\\' . $matches[1] . '::' . $matches[2]);
    }

    /**
     * This library and the event loop: a task runs in a callback of Revolt, called from its own code.
     *
     * @return non-empty-list<non-empty-string>
     */
    private static function libraries(): array
    {
        /** @var non-empty-list<non-empty-string> $libraries */
        static $libraries = [
            \dirname(__DIR__),
            \dirname((string) new \ReflectionClass(EventLoop::class)->getFileName()),
        ];

        return $libraries;
    }
}

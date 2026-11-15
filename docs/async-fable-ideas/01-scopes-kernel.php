<?php

/**
 * ПОДХОД 1. Ambient cancel scopes + nursery (Trio / Kotlin coroutineScope / Java StructuredTaskScope).
 *
 * Это РАБОЧИЙ прототип ядра поверх Revolt (PHP 8.5+), примеры — в 01-scopes-examples.php.
 *
 * Идеи реализации:
 *
 * 1. Отмена — свойство ДЕРЕВА ЗАДАЧ, а не параметр функции. Никаких `?Cancellation $cancellation = null`
 *    в сигнатурах: текущий Scope лежит в FiberLocal, его видит любая точка приостановки.
 *
 * 2. Одна точка приостановки на всю библиотеку — {@see suspend()}. Всё остальное (sleep, чтение сокета,
 *    Task::await, канал) выражается через неё. Поэтому отмену достаточно реализовать ровно в одном месте.
 *
 * 3. Одноразовый {@see Waker} вместо Revolt Suspension. У Revolt Suspension один на файбер и переиспользуется:
 *    если бросить в файбер отмену, а потом опоздавший колбэк вызовет resume(), он разбудит ЧУЖОЕ ожидание.
 *    Waker срабатывает один раз, опоздавшие resume/throw игнорируются. Заодно он умеет «сработать до парковки».
 *
 * 4. Отмена level-triggered (как в Trio, в отличие от asyncio/Amp): пока scope отменён, КАЖДЫЙ suspend() в нём
 *    бросает Cancelled. Проглотить отмену через `catch (\Throwable)` нельзя — она вернётся на следующей же
 *    точке приостановки. Асинхронная очистка делается явно через shield().
 *
 * 5. Cancelled extends \Error, а не \Exception: типовой `catch (\Exception $e) { log; retry; }` её не ловит.
 *    Cancelled знает scope-источник, поэтому timeout() отличает «мой таймаут» от «отменили снаружи».
 *
 * 6. Неструктурного спавна нет вообще: файбер можно запустить только через Group, а group() не вернёт
 *    управление, пока не завершатся все дети. Утёкших задач и UnhandledFutureError не бывает по построению.
 */

declare(strict_types=1);

namespace Ideas;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Revolt\EventLoop;
use Revolt\EventLoop\FiberLocal;
use Revolt\EventLoop\Suspension;

final class Cancelled extends \Error
{
    public function __construct(
        public readonly Scope $origin,
    ) {
        parent::__construct('Scope was cancelled');
    }
}

final class TimedOut extends \Exception {}

/**
 * Одноразовая «ручка» для пробуждения ровно одного ожидания.
 *
 * @template T
 */
final class Waker
{
    private const int ARMED = 0;
    private const int PARKED = 1;
    private const int FIRED = 2;

    private int $state = self::ARMED;
    private mixed $value = null;
    private ?\Throwable $error = null;
    private ?Suspension $suspension = null;

    /**
     * @param T $value
     */
    public function resume(mixed $value = null): bool
    {
        if ($this->state === self::FIRED) {
            return false;
        }

        $parked = $this->state === self::PARKED;
        $this->state = self::FIRED;
        $parked ? $this->suspension->resume($value) : $this->value = $value;

        return true;
    }

    public function throw(\Throwable $error): bool
    {
        if ($this->state === self::FIRED) {
            return false;
        }

        $parked = $this->state === self::PARKED;
        $this->state = self::FIRED;
        $parked ? $this->suspension->throw($error) : $this->error = $error;

        return true;
    }

    /**
     * Неотменяемая парковка. Отменяемая — только через suspend().
     *
     * @internal
     * @return T
     */
    public function park(): mixed
    {
        if ($this->state === self::FIRED) {
            return $this->error === null ? $this->value : throw $this->error;
        }

        $this->state = self::PARKED;
        $this->suspension = EventLoop::getSuspension();

        return $this->suspension->suspend();
    }
}

/**
 * Узел дерева отмены. Задача всегда выполняется «внутри» какого-то scope (FiberLocal).
 */
final class Scope
{
    private static ?FiberLocal $current = null;
    private static ?self $root = null;

    private bool $cancelled = false;

    /** @var array<int, \Closure(self): void> */
    private array $listeners = [];

    /** @var array<int, self> */
    private array $children = [];

    private int $nextListenerId = 0;

    public function __construct(
        private readonly ?self $parent = null,
        private readonly bool $shield = false,
    ) {
        if ($parent !== null) {
            $parent->children[spl_object_id($this)] = $this;
        }
    }

    public static function current(): self
    {
        return self::local()->get();
    }

    /**
     * Самый внешний отменённый scope, видимый отсюда. Shield обрезает видимость предков.
     * «Самый внешний» важен: вложенный timeout() не должен принять чужую отмену за свой таймаут.
     */
    public function cancelOrigin(): ?self
    {
        $origin = null;

        for ($scope = $this; $scope !== null; $scope = $scope->shield ? null : $scope->parent) {
            if ($scope->cancelled) {
                $origin = $scope;
            }
        }

        return $origin;
    }

    public function isCancelled(): bool
    {
        return $this->cancelOrigin() !== null;
    }

    /**
     * Явная точка отмены для CPU-циклов без IO.
     */
    public function checkpoint(): void
    {
        $origin = $this->cancelOrigin();

        if ($origin !== null) {
            throw new Cancelled($origin);
        }
    }

    public function cancel(): void
    {
        if (!$this->cancelled) {
            $this->cancelled = true;
            $this->notify($this);
        }
    }

    /**
     * @param \Closure(self): void $listener
     * @return \Closure(): void отписка
     */
    public function onCancel(\Closure $listener): \Closure
    {
        $origin = $this->cancelOrigin();

        if ($origin !== null) {
            $listener($origin);

            return static fn() => null;
        }

        $id = $this->nextListenerId++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /**
     * Выполняет $body в текущем файбере, но с этим scope в качестве текущего.
     *
     * @template T
     * @param \Closure(): T $body
     * @return T
     */
    public function enter(\Closure $body): mixed
    {
        $local = self::local();
        $previous = $local->get();
        $local->set($this);

        try {
            return $body();
        } finally {
            $local->set($previous);
        }
    }

    /**
     * Отцепляет scope от родителя. Родитель держит детей сильной ссылкой, чтобы доставить им отмену.
     */
    public function close(): void
    {
        if ($this->parent !== null) {
            unset($this->parent->children[spl_object_id($this)]);
        }
    }

    private function notify(self $origin): void
    {
        $listeners = $this->listeners;
        $this->listeners = [];

        foreach ($listeners as $listener) {
            $listener($origin);
        }

        foreach ($this->children as $child) {
            if (!$child->shield) {
                $child->notify($origin);
            }
        }
    }

    private static function local(): FiberLocal
    {
        return self::$current ??= new FiberLocal(static fn(): self => self::$root ??= new self());
    }
}

/**
 * ЕДИНСТВЕННАЯ точка приостановки. Аналог suspendCancellableCoroutine из Kotlin.
 *
 * $arm получает Waker, регистрирует его где нужно (таймер, watcher сокета, очередь ожидающих)
 * и может вернуть $disarm — как снять регистрацию, если ожидание прервано отменой.
 *
 * @template T
 * @param \Closure(Waker<T>): (null|\Closure(): void) $arm
 * @return T
 */
function suspend(\Closure $arm): mixed
{
    $scope = Scope::current();
    $scope->checkpoint();

    $waker = new Waker();
    $disarm = $arm($waker);
    $interrupted = false;

    $unsubscribe = $scope->onCancel(static function (Scope $origin) use ($waker, &$interrupted): void {
        // Если источник уже успел сработать, отмена проигрывает: значение не теряется
        // (критично для каналов — принятое сообщение нельзя выбросить), а Cancelled придёт на следующем suspend().
        $interrupted = $waker->throw(new Cancelled($origin));
    });

    try {
        return $waker->park();
    } finally {
        $unsubscribe();

        if ($interrupted && $disarm !== null) {
            $disarm();
        }
    }
}

function sleep(float $seconds): void
{
    suspend(static function (Waker $waker) use ($seconds): \Closure {
        $timer = EventLoop::delay($seconds, static function () use ($waker): void {
            $waker->resume();
        });

        return static fn() => EventLoop::cancel($timer);
    });
}

/**
 * Результат дочерней задачи. Не Future: получить его можно только внутри группы-владельца.
 *
 * @template T
 */
final class Task
{
    private bool $done = false;
    private mixed $value = null;
    private ?\Throwable $error = null;

    /** @var array<int, Waker<null>> */
    private array $waiters = [];

    /**
     * @internal
     */
    public function __construct(
        private readonly Scope $scope,
    ) {}

    public function cancel(): void
    {
        $this->scope->cancel();
    }

    /**
     * @return T
     */
    public function await(): mixed
    {
        if (!$this->done) {
            suspend(function (Waker $waker): \Closure {
                $id = spl_object_id($waker);
                $this->waiters[$id] = $waker;

                return function () use ($id): void {
                    unset($this->waiters[$id]);
                };
            });
        }

        return $this->error === null ? $this->value : throw $this->error;
    }

    /**
     * @internal
     */
    public function settle(mixed $value, ?\Throwable $error): void
    {
        $this->done = true;
        $this->value = $value;
        $this->error = $error;

        $waiters = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            $waiter->resume();
        }
    }
}

/**
 * Nursery: единственный способ запустить конкурентную задачу.
 */
final class Group
{
    public private(set) ?\Throwable $error = null;

    private int $tasks = 0;
    private int $daemons = 0;

    /** @var array<int, Scope> */
    private array $daemonScopes = [];

    private ?Waker $joiner = null;

    /**
     * @internal
     */
    public function __construct(
        private readonly Scope $scope,
    ) {}

    /**
     * Группа ждёт завершения задачи; ошибка задачи отменяет всю группу и вылетает из group().
     *
     * @template T
     * @param \Closure(): T $fn
     * @return Task<T>
     */
    public function go(\Closure $fn): Task
    {
        return $this->start($fn, daemon: false);
    }

    /**
     * Фоновая задача (heartbeat, чтение пингов, метрики): группа её НЕ ждёт,
     * а отменяет, как только завершились тело и все обычные задачи.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return Task<T>
     */
    public function daemon(\Closure $fn): Task
    {
        return $this->start($fn, daemon: true);
    }

    public function cancel(): void
    {
        $this->scope->cancel();
    }

    /**
     * @internal
     */
    public function fail(\Throwable $error): void
    {
        $this->error ??= $error;
        $this->scope->cancel();
    }

    /**
     * Неотменяемое ожидание детей: это и есть гарантия структурности.
     * Внешняя отмена доходит до детей через дерево scope, а родитель всё равно их дожидается.
     *
     * @internal
     */
    public function join(): void
    {
        while ($this->tasks > 0) {
            ($this->joiner = new Waker())->park();
        }

        foreach ($this->daemonScopes as $scope) {
            $scope->cancel();
        }

        while ($this->daemons > 0) {
            ($this->joiner = new Waker())->park();
        }
    }

    private function start(\Closure $fn, bool $daemon): Task
    {
        // У каждой задачи свой scope: её можно отменить отдельно через Task::cancel().
        $scope = new Scope($this->scope);
        $task = new Task($scope);

        if ($daemon) {
            ++$this->daemons;
            $this->daemonScopes[spl_object_id($scope)] = $scope;
        } else {
            ++$this->tasks;
        }

        EventLoop::queue(function () use ($fn, $scope, $task, $daemon): void {
            try {
                $task->settle($scope->enter($fn), null);
            } catch (Cancelled $cancelled) {
                // Отмена — не ошибка: группу не роняет.
                $task->settle(null, $cancelled);
            } catch (\Throwable $error) {
                $task->settle(null, $error);
                $this->fail($error);
            } finally {
                $scope->close();

                if ($daemon) {
                    --$this->daemons;
                    unset($this->daemonScopes[spl_object_id($scope)]);
                } else {
                    --$this->tasks;
                }

                $joiner = $this->joiner;
                $this->joiner = null;
                $joiner?->resume();
            }
        });

        return $task;
    }
}

/**
 * @template T
 * @param \Closure(Group): T $body
 * @return T
 */
function group(\Closure $body): mixed
{
    $scope = new Scope(Scope::current());
    $group = new Group($scope);
    $result = null;
    $outerCancellation = null;

    try {
        $result = $scope->enter(static fn(): mixed => $body($group));
    } catch (Cancelled $cancelled) {
        // Своя отмена ($group->cancel() или упавший ребёнок) — штатный выход из тела.
        // Чужая — пробрасывается дальше, но только после join().
        if ($cancelled->origin !== $scope) {
            $outerCancellation = $cancelled;
        }
    } catch (\Throwable $error) {
        $group->fail($error);
    }

    $group->join();
    $scope->close();

    if ($group->error !== null) {
        throw $group->error;
    }

    if ($outerCancellation !== null) {
        throw $outerCancellation;
    }

    return $result;
}

/**
 * Дедлайн на всё, что происходит внутри $body, включая любые вложенные группы.
 *
 * @template T
 * @param \Closure(): T $body
 * @return T
 * @throws TimedOut
 */
function timeout(float $seconds, \Closure $body): mixed
{
    $scope = new Scope(Scope::current());
    $timer = EventLoop::delay($seconds, static function () use ($scope): void {
        $scope->cancel();
    });

    try {
        return $scope->enter($body);
    } catch (Cancelled $cancelled) {
        throw $cancelled->origin === $scope ? new TimedOut("Timed out after {$seconds}s") : $cancelled;
    } finally {
        EventLoop::cancel($timer);
        $scope->close();
    }
}

/**
 * Внешняя отмена внутрь не проникает. Для очистки: rollback, close frame, возврат соединения в пул.
 *
 * @template T
 * @param \Closure(): T $body
 * @return T
 */
function shield(\Closure $body): mixed
{
    $scope = new Scope(Scope::current(), shield: true);

    try {
        return $scope->enter($body);
    } finally {
        $scope->close();
    }
}

/**
 * Все результаты или первая ошибка (остальные при этом отменяются).
 *
 * @template K of array-key
 * @template T
 * @param array<K, \Closure(): T> $fns
 * @return array<K, T>
 */
function all(array $fns): array
{
    return group(static function (Group $group) use ($fns): array {
        $tasks = array_map($group->go(...), $fns);

        return array_map(static fn(Task $task): mixed => $task->await(), $tasks);
    });
}

/**
 * Первый завершившийся побеждает, проигравшие отменяются И ДОЖИДАЮТСЯ.
 *
 * @template T
 * @param \Closure(): T ...$fns
 * @return T
 */
function race(\Closure ...$fns): mixed
{
    $won = false;
    $result = null;

    group(static function (Group $group) use ($fns, &$won, &$result): void {
        foreach ($fns as $fn) {
            $group->go(static function () use ($fn, $group, &$won, &$result): void {
                $value = $fn();

                if (!$won) {
                    $won = true;
                    $result = $value;
                    $group->cancel();
                }
            });
        }
    });

    return $result;
}

/**
 * Ограниченная конкурентность: $concurrency воркеров тянут из общего итератора.
 * (В прототипе сам итератор не должен приостанавливаться.)
 *
 * @template T
 * @param iterable<T> $items
 * @param \Closure(T): void $fn
 */
function each(iterable $items, int $concurrency, \Closure $fn): void
{
    $iterator = (static fn(): \Generator => yield from $items)();

    group(static function (Group $group) use ($iterator, $concurrency, $fn): void {
        for ($i = 0; $i < $concurrency; ++$i) {
            $group->go(static function () use ($iterator, $fn): void {
                while ($iterator->valid()) {
                    $item = $iterator->current();
                    $iterator->next();
                    $fn($item);
                }
            });
        }
    });
}

/**
 * Мост в экосистему amphp v3: превращает текущий scope в Amp\Cancellation на время одного вызова.
 *
 *     $response = amp(fn (Cancellation $c) => $httpClient->request($request, $c));
 *     $response = amp($httpClient->request($request, ?));   // PHP 8.6, partial application
 *
 * Библиотека, которая Cancellation не принимает, ведёт себя как shield(): отмена подождёт её возврата.
 *
 * @template T
 * @param \Closure(Cancellation): T $call
 * @return T
 */
function amp(\Closure $call): mixed
{
    $scope = Scope::current();
    $scope->checkpoint();

    $deferred = new DeferredCancellation();
    $unsubscribe = $scope->onCancel(static fn() => $deferred->cancel());

    try {
        return $call($deferred->getCancellation());
    } catch (CancelledException $exception) {
        $scope->checkpoint(); // наша отмена → Cancelled; чужая CancelledException летит как была

        throw $exception;
    } finally {
        $unsubscribe();
    }
}

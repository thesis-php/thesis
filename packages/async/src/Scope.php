<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop\FiberLocal;

/**
 * Node of the cancellation tree. Every fiber always runs inside some scope.
 *
 * @api
 */
final class Scope
{
    /** @var ?FiberLocal<self> */
    private static ?FiberLocal $current = null;

    private static ?self $root = null;

    private bool $cancelled = false;

    /** @var array<int, callable(self): void> */
    private array $listeners = [];

    /** @var array<int, self> */
    private array $children = [];

    private int $nextListenerId = 0;

    /**
     * @internal
     */
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

    public function isCancelled(): bool
    {
        return $this->cancelOrigin() !== null;
    }

    /**
     * Explicit cancellation point for CPU-bound loops that never suspend.
     *
     * @throws Cancelled
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
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->notify($this);
    }

    /**
     * The listener is called at most once, synchronously inside {@see cancel()},
     * or immediately if the scope is already cancelled. It must not throw.
     *
     * @param callable(self): void $listener receives the cancelled scope
     * @return \Closure(): void unsubscribe
     */
    public function onCancel(callable $listener): \Closure
    {
        $origin = $this->cancelOrigin();

        if ($origin !== null) {
            $listener($origin);

            return static function (): void {};
        }

        $id = $this->nextListenerId++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /**
     * Runs $body in the current fiber with this scope as the current one.
     *
     * @internal
     * @template T
     * @param callable(): T $body
     * @return T
     */
    public function enter(callable $body): mixed
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
     * Detaches the scope from its parent, which holds children strongly to deliver cancellation.
     *
     * @internal
     */
    public function close(): void
    {
        if ($this->parent !== null) {
            unset($this->parent->children[spl_object_id($this)]);
        }
    }

    /**
     * The outermost cancelled scope visible from here; a shield hides its ancestors.
     */
    private function cancelOrigin(): ?self
    {
        $origin = null;

        for ($scope = $this; $scope !== null; $scope = $scope->shield ? null : $scope->parent) {
            if ($scope->cancelled) {
                $origin = $scope;
            }
        }

        return $origin;
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

    /**
     * @return FiberLocal<self>
     */
    private static function local(): FiberLocal
    {
        return self::$current ??= new FiberLocal(static fn(): self => self::$root ??= new self());
    }
}

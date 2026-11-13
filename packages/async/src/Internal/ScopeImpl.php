<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Cancelled;
use Thesis\Async\Scope;

/**
 * @internal
 */
final class ScopeImpl implements Scope
{
    private bool $cancelled = false;

    /** @var array<int, callable(Scope): void> */
    private array $listeners = [];

    /** @var array<int, self> */
    private array $children = [];

    private int $nextListenerId = 0;

    #[\Override]
    public bool $isCancelled {
        get => $this->cancelOrigin() !== null;
    }

    public function __construct(
        private readonly ?self $parent = null,
        private readonly bool $shield = false,
    ) {
        if ($parent !== null) {
            $parent->children[spl_object_id($this)] = $this;
        }
    }

    public function checkpoint(): void
    {
        $origin = $this->cancelOrigin();

        if ($origin !== null) {
            throw new Cancelled($origin);
        }
    }

    public function cancel(): void
    {
        if ($this->parent === null) {
            throw new \LogicException('The root scope cannot be cancelled.');
        }

        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->notify($this);
    }

    public function onCancel(callable $listener): \Closure
    {
        $origin = $this->cancelOrigin();

        if ($origin !== null) {
            $listener($origin);

            return static function () {};
        }

        $id = $this->nextListenerId++;
        $this->listeners[$id] = $listener;

        return function () use ($id) {
            unset($this->listeners[$id]);
        };
    }

    /**
     * Detaches the scope from its parent, which holds children strongly to deliver cancellation.
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
}

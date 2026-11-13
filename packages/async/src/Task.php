<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Handle of a child task started via {@see Group}.
 *
 * @api
 * @template-covariant T
 */
final class Task
{
    public private(set) bool $isDone = false;

    /** @var ?T */
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

    /**
     * Cancels this task only; its group and siblings keep running.
     */
    public function cancel(): void
    {
        $this->scope->cancel();
    }

    /**
     * @return T
     * @throws Cancelled if the awaiting scope or the task itself was cancelled
     * @throws \Throwable the task failure
     */
    public function await(): mixed
    {
        if (!$this->isDone) {
            suspend(
                function (Waker $waker) {
                    /** @var Waker<null> $waker */
                    $id = spl_object_id($waker);
                    $this->waiters[$id] = $waker;

                    return function () use ($id) {
                        unset($this->waiters[$id]);
                    };
                },
            );
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        /** @var T */
        return $this->value;
    }

    /**
     * @internal
     */
    public function settle(mixed $value, ?\Throwable $error): void
    {
        $this->isDone = true;
        /** @var ?T $value */
        $this->value = $value;
        $this->error = $error;

        $waiters = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            $waiter->resume();
        }
    }
}

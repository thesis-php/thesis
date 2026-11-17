<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Amp\Cancellation;
use Amp\CancelledException;

/**
 * {@see Cancellation} that is requested exactly while its scope is cancelled.
 *
 * It holds no cancellation state of its own, only the unsubscribe functions of its subscriptions by id:
 * {@see isRequested()} reads the scope's flag, {@see subscribe()} goes through {@see Scope::onCancel()}.
 * So it costs nothing until amphp subscribes, and a subscription that has been removed
 * is never called, even if the cancellation has already been queued: amphp's own code relies on that
 * to not wake a suspension that has moved on to another wait.
 *
 * @internal
 */
final class ScopeCancellation implements Cancellation
{
    /**
     * @var array<int, \Closure(): void> keyed by appending: PHP never reuses such a key, even after unset()
     */
    private array $unsubscribes = [];

    public function __construct(
        private readonly Scope $scope,
    ) {}

    /**
     * Amp\Cancellation documents this signature, but its type is \Closure.
     *
     * @param \Closure(CancelledException): void $callback
     */
    #[\Override] // @phpstan-ignore method.childParameterType
    public function subscribe(\Closure $callback): string
    {
        $this->unsubscribes[] = $this->scope->onCancel(static function () use ($callback) {
            $callback(new CancelledException());
        });

        return (string) array_key_last($this->unsubscribes);
    }

    #[\Override]
    public function unsubscribe(string $id): void
    {
        if (isset($this->unsubscribes[$id])) {
            $this->unsubscribes[$id]();
            unset($this->unsubscribes[$id]);
        }
    }

    #[\Override]
    public function isRequested(): bool
    {
        return $this->scope->isCancelled;
    }

    #[\Override]
    public function throwIfRequested(): void
    {
        if ($this->scope->isCancelled) {
            throw new CancelledException();
        }
    }
}

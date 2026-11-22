<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\Scope;

/**
 * Lazily runs a callback in the first caller's scope and shares its value or error with all awaiters.
 *
 * @api
 * @template-covariant T
 */
final class Once
{
    /**
     * @var \Closure(): T
     */
    private \Closure $fn;

    /**
     * @var ?Deferred<T>
     */
    private ?Deferred $deferred = null;

    /**
     * @param callable(): T $fn
     * @param bool $retryOnCancel whether another caller may run $fn again if it throws Cancelled
     */
    public function __construct(
        callable $fn,
        private readonly bool $retryOnCancel = false,
    ) {
        $this->fn = $fn(...);
    }

    /**
     * @return T
     * @throws Cancelled if this caller is cancelled while running or waiting for the callback
     * @throws OnceCancelled if another caller was cancelled in the callback and retries are disabled
     */
    public function await(): mixed
    {
        while (($deferred = $this->deferred) !== null) {
            try {
                return $deferred->await();
            } catch (OnceCancelled $exception) {
                // The callback can itself throw OnceCancelled from a nested Once. Only a discarded attempt retries.
                if ($deferred === $this->deferred) {
                    throw $exception;
                }

                Scope::current()->throwIfCancelled();
            }
        }

        $deferred = $this->deferred = new Deferred();

        try {
            $value = ($this->fn)();

            $deferred->complete($value);

            return $value;
        } catch (Cancelled $cancelled) {
            if ($this->retryOnCancel) {
                $this->deferred = null;
            }

            $deferred->fail(new OnceCancelled($cancelled));

            throw $cancelled;
        } catch (\Throwable $exception) {
            $deferred->fail($exception);

            throw $exception;
        } finally {
            if ($this->deferred !== null) {
                unset($this->fn);
            }
        }
    }

    private function __clone() {}
}

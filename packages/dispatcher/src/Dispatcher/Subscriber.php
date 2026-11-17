<?php

declare(strict_types=1);

namespace Thesis\Dispatcher;

use Thesis\Dispatcher;

/**
 * An object with several listeners. Given to `Thesis\Dispatcher` among the listeners, it stands for its own:
 * `new Dispatcher([$audit, $listener])`. It is never called itself, an invokable one too.
 *
 * @api
 */
interface Subscriber
{
    /**
     * Called once, by the constructor of the dispatcher, which checks each of them as any listener.
     *
     * @return iterable<callable(never, Dispatcher): void> in the order they are called, the keys are ignored
     */
    public function listeners(): iterable;
}

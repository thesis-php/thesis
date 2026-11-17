<?php

declare(strict_types=1);

namespace Thesis\Dispatcher;

use function Thesis\Formatter\formatClass;
use function Thesis\Formatter\formatReflectedFunction;

/**
 * The methods of $subscriber marked #[Listener], to give to `Thesis\Dispatcher` with other listeners:
 * `new Dispatcher([...listenersOf($audit), $listener])`. They come in the order of `getMethods()`:
 * those declared in its class first, then the inherited ones. The dispatcher checks each of them as any listener.
 *
 * @api
 * @return non-empty-list<\Closure(never): void>
 * @throws \InvalidArgumentException if a marked method is not public or no method is marked
 */
function listenersOf(object $subscriber): array
{
    $listeners = [];

    foreach (new \ReflectionClass($subscriber)->getMethods() as $method) {
        if ($method->getAttributes(Listener::class) === []) {
            continue;
        }

        if (!$method->isPublic()) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be public to be a listener.',
                formatReflectedFunction($method),
            ));
        }

        $listeners[] = $method->getClosure($subscriber);
    }

    if ($listeners === []) {
        throw new \InvalidArgumentException(\sprintf(
            '%s has no method marked #[%s].',
            formatClass($subscriber),
            Listener::class,
        ));
    }

    return $listeners;
}

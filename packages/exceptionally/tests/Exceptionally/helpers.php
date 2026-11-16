<?php

declare(strict_types=1);

namespace Thesis\Exceptionally;

/**
 * An unsuppressed deprecation, see AGENTS.md.
 */
function deprecate(string $message): void
{
    trigger_error($message, E_USER_DEPRECATED);
}

/**
 * The whole stack, the top last: PHP shows only the top, so the handlers are taken off and set again.
 *
 * @return list<callable(int, string, string, int): mixed>
 */
function errorHandlers(): array
{
    $handlers = [];

    while (($top = get_error_handler()) !== null) {
        $handlers[] = $top;
        restore_error_handler();
    }

    $handlers = array_reverse($handlers);

    foreach ($handlers as $handler) {
        set_error_handler($handler);
    }

    return $handlers;
}

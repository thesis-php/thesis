<?php

declare(strict_types=1);

namespace Thesis;

/**
 * An unsuppressed deprecation: the manifest keeps PHP CS Fixer away from this file,
 * its error_suppression rule puts @ before every trigger_error() of E_USER_DEPRECATED.
 */
function deprecate(string $message): void
{
    trigger_error($message, E_USER_DEPRECATED);
}

/**
 * The error handlers on the stack, the top one last: PHP shows only the top, so they are taken off and set again.
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

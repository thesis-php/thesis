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

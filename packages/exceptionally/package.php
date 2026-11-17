<?php

declare(strict_types=1);

use Thesis\Exceptionally\Internal\ErrorHandler;
use Thesis\Monorepo\FixerSkip;
use Thesis\Monorepo\InfectionSkip;
use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Turns PHP errors into exceptions around a callable, safe when the callable suspends a fiber.',
    licenseSince: 2026,
    version: '0.4',
    keywords: ['php', 'error-handling', 'exceptions', 'warnings', 'fibers'],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    rootEntry: true,
    skip: [
        new FixerSkip(
            paths: ['tests/Exceptionally/helpers.php'],
            reason: 'error_suppression puts @ before trigger_error() of E_USER_DEPRECATED, the tests need it unsuppressed.',
        ),
        new InfectionSkip(
            mutator: 'IncrementInteger',
            class: ErrorHandler::class,
            method: '__invoke',
            reason: 'The levels of a fiber without a call: 1 differs from 0 only in E_ERROR, which PHP never passes to a handler of the application.',
        ),
    ],
);

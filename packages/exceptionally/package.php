<?php

declare(strict_types=1);

use Thesis\Monorepo\FixerSkip;
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
    ],
);

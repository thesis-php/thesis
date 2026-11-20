<?php

declare(strict_types=1);

use Thesis\Monorepo\FixerSkip;
use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Turns PHP errors into exceptions around a callable, safe when the callable suspends a fiber.',
    licenseSince: 2026,
    version: '0.4',
    keywords: ['php', 'error-handling', 'exceptions', 'warnings', 'fibers'],
    require: [
        // 8.2 is the lowest PHP that Testo runs on. 8.2.2: before it the cycle collector did not see the stack
        // of a fiber (php-src GH-9735), so an abandoned fiber and the handler of its call stayed until the exit.
        'php' => '^8.2.2',
        // get_error_handler() of PHP 8.5.
        'symfony/polyfill-php85' => '^1.32',
    ],
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

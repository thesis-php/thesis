<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Non-blocking TCP and Unix sockets with TLS and an async DNS resolver, cancelled by the task tree.',
    licenseSince: 2026,
    require: [
        'ext-filter' => '*',
        'ext-openssl' => '*',
        'thesis/async' => '*',
        'thesis/io' => '*',
    ],
    requireDev: [
        'ext-intl' => '*',
        'ext-sockets' => '*',
        'revolt/event-loop' => '^1.0.9',
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    files: ['src/autoload.files.php'],
    devFiles: ['tests/autoload.files.php'],
    suggest: [
        'ext-intl' => 'To resolve internationalized host names (Пример.test)',
        'ext-sockets' => 'To report why a connection failed (refused, unreachable) instead of a generic message',
    ],
);

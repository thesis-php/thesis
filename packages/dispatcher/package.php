<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Synchronous event dispatcher that matches events to listeners by parameter type.',
    licenseSince: 2026,
    version: '0.4',
    keywords: ['php', 'event-dispatcher', 'events'],
    require: [
        // The PHP of thesis/formatter. The package itself needs 8.4 too: array_any() and array_all().
        'php' => '^8.4',
        'thesis/formatter' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    rootEntry: true,
);

<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Testo plugins for thesis packages: tests in the event loop of thesis/async, real or virtual time, PHP errors as failures.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'testing', 'testo', 'event-loop', 'virtual-time', 'error-handling'],
    require: [
        'internal/container' => '^1.1',
        'testo/testo' => '^0.10.29',
        'thesis/async' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'thesis/time' => '*',
    ],
);

<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Testo plugins for testing thesis/async code with real or virtual time and PHP errors as failures.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'testing', 'testo', 'event-loop', 'virtual-time', 'error-handling'],
    require: [
        'internal/container' => '^1.1',
        'testo/testo' => '^0.10.39',
        'thesis/async' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'thesis/time' => '*',
    ],
);

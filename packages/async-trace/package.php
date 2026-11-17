<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Tools around the trace of thesis/async: its events written as NDJSON, one line per event, and as lines of text for tests.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'fibers', 'tracing', 'ndjson', 'debugging'],
    require: [
        'psr/clock' => '^1.0',
        'thesis/async' => '*',
        'thesis/dispatcher' => '*',
        'thesis/formatter' => '*',
        'thesis/source-location' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
);

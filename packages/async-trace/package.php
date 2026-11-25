<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;
use Thesis\TestoPlugin\VirtualTimePlugin;

return new Manifest(
    description: 'Trace thesis/async tasks to diagnose cancellation, blocking code and long waits.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'fibers', 'tracing', 'ndjson', 'debugging', 'cancellation', 'blocking', 'watchdog'],
    require: [
        // Durations and times of the trace are int nanoseconds (toNs()), on 32-bit only up to 2.1 seconds.
        'php-64bit' => '*',
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
    testoPlugins: [new VirtualTimePlugin()],
);

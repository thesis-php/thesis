<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;
use Thesis\TestoPlugin\VirtualTimePlugin;

return new Manifest(
    description: 'PSR-3 logger with a configurable clock, bounded buffering, '
        . 'and asynchronous JSON and colored console output.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'logging', 'psr-3', 'psr-20', 'ndjson', 'console'],
    require: [
        'psr/clock' => '^1.0',
        'psr/log' => '^3.0',
        'thesis/async' => '*',
        'thesis/io' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'monolog/monolog' => '^3.0',
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/dispatcher' => '*',
        'thesis/testo-plugin' => '*',
    ],
    rootEntry: true,
    testoPlugins: [new VirtualTimePlugin()],
);

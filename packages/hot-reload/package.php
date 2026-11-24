<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Restart a development server on file changes or SIGHUP, including after a failed launch.',
    licenseSince: 2026,
    version: '0.5',
    keywords: ['php', 'hot-reload', 'file-watcher', 'process', 'development', 'unix'],
    require: [
        'composer-runtime-api' => '^2.2', // The vendor-bin proxy supplies $_composer_autoload_path.
        'ext-pcntl' => '*',
        'thesis/async' => '*',
        'thesis/exceptionally' => '*',
        'thesis/io' => '*',
        'thesis/process' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    bin: ['bin/hot-reload'],
    // The CLI test runs the supervisor in a subprocess, outside Infection's per-test coverage.
    mutationTesting: false,
);

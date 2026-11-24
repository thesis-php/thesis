<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Group-owned Unix worker processes with signal handling and inherited IPC descriptors.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'fibers', 'process', 'subprocess', 'workers', 'ipc', 'unix'],
    require: [
        'ext-pcntl' => '*',
        'thesis/async' => '*',
        'thesis/exceptionally' => '*',
        'thesis/io' => '*',
    ],
    requireDev: [
        'amphp/process' => '^2.1',
        'symfony/process' => '^8.1',
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/dispatcher' => '*',
        'thesis/time' => '*',
    ],
);

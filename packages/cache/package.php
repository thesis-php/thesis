<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Cache interface with Time\Duration TTLs, a local LRU cache and stampede protection by a keyed mutex.',
    licenseSince: 2026,
    version: '0.1',
    require: [
        'thesis/async' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
);

<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Fast binary I/O: byte buffer with typed encoding, non-blocking streams and stdio.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'fibers', 'io', 'non-blocking-io', 'streams', 'buffer', 'binary'],
    require: [
        'revolt/event-loop' => '^1.0.9',
        'thesis/async' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/lifecycle' => '^0.1.9',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
);

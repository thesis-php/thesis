<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Synchronous event dispatcher: a listener gets the events of the type of its parameter, resolved once per class.',
    licenseSince: 2026,
    version: '0.3',
    require: [
        'thesis/formatter' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
);

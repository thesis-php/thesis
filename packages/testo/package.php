<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Testo plugins for thesis packages: tests on the virtual time of thesis/async.',
    licenseSince: 2026,
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

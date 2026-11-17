<?php

declare(strict_types=1);

use Thesis\Dispatcher;
use Thesis\Monorepo\InfectionSkip;
use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Synchronous event dispatcher: a listener gets the events of the type of its parameter, resolved once per class.',
    licenseSince: 2026,
    version: '0.3',
    keywords: ['php', 'event-dispatcher', 'events'],
    require: [
        'thesis/formatter' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    rootEntry: true,
    skip: [
        new InfectionSkip(
            mutators: ['Coalesce'],
            class: Dispatcher::class,
            method: 'dispatch',
            reason: 'Without the table every event resolves its listeners again and gets the same ones: only slower, which no test sees.',
        ),
    ],
);

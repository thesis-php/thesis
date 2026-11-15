<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'AMQP 0.9.1 client for RabbitMQ: publisher confirms, consumers as iterators, reconnects.',
    licenseSince: 2026,
    require: [
        'ext-filter' => '*',
        'ext-uri' => '*',
        'thesis/async' => '*',
        'thesis/io' => '*',
        'thesis/net' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'ext-simplexml' => '*',
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
    files: ['src/autoload.files.php'],
    devFiles: ['tests/autoload.files.php'],
);

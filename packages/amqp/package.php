<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'AMQP 0.9.1 client for RabbitMQ: publisher confirms, consumers as iterators, reconnects.',
    licenseSince: 2026,
    version: '2.0',
    title: 'Thesis AMQP',
    keywords: ['php', 'async', 'fibers', 'amqp', 'amqp-client', 'rabbitmq', 'message-queue'],
    require: [
        // uint32 frame-max (0xFF_FF_FF_FF), uint64 delivery tags and body sizes, toNs() of 30 seconds.
        'php-64bit' => '*',
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
    // The test of the protocol layer compares the files in src/ with what generate() gives
    devFiles: ['codegen/generate.php'],
);

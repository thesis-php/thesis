<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;
use Thesis\TestoPlugin\EventLoopPlugin;

return new Manifest(
    description: 'Non-blocking TCP and Unix sockets with TLS and an async DNS resolver, cancelled by the task tree.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'fibers', 'networking', 'sockets', 'tcp', 'unix-socket', 'tls', 'dns'],
    require: [
        // The uint32 TTL of a DNS record fits in int only on 64-bit.
        'php-64bit' => '*',
        'ext-filter' => '*',
        'ext-openssl' => '*',
        'thesis/async' => '*',
        'thesis/io' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'ext-intl' => '*',
        'ext-sockets' => '*',
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
    suggest: [
        'ext-intl' => 'To resolve internationalized host names (Пример.test)',
        'ext-sockets' => 'To report why a connection failed (refused, unreachable) instead of a generic message',
    ],
    optional: ['ext-intl', 'ext-sockets'],
    testoPlugins: [new EventLoopPlugin()],
);

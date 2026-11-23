<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;
use Thesis\TestoPlugin\EventLoopPlugin;

return new Manifest(
    description: 'Asynchronous HTTP/1 server for TCP and Unix sockets with streaming bodies, trailers, TLS '
        . 'and graceful shutdown.',
    licenseSince: 2026,
    version: '0.1',
    title: 'Thesis HTTP Server',
    keywords: ['php', 'async', 'http', 'http1', 'http-server', 'streaming', 'keep-alive', 'tcp', 'unix-socket', 'tls'],
    require: [
        'thesis/async' => '*',
        'thesis/http' => '*',
        'thesis/io' => '*',
        'thesis/net' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'ext-openssl' => '*',
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
    testoPlugins: [new EventLoopPlugin()],
);

<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;
use Thesis\TestoPlugin\EventLoopPlugin;

return new Manifest(
    description: 'Asynchronous HTTP/1 client and server with connection pooling, routing and streamed bodies.',
    licenseSince: 2026,
    version: '0.1',
    title: 'Thesis HTTP',
    keywords: [
        'php',
        'async',
        'http',
        'http1',
        'http-client',
        'http-server',
        'streaming',
        'tls',
        'http-message',
        'http-headers',
        'router',
        'query-string',
        'http-trailers',
    ],
    require: [
        // Content-Length and chunk sizes can exceed 2^31.
        'php-64bit' => '*',
        'ext-zlib' => '*',
        'psr/log' => '^3.0',
        'thesis/async' => '*',
        'thesis/dispatcher' => '*',
        'thesis/net' => '*',
        'thesis/time' => '*',
        'thesis/io' => '*',
    ],
    requireDev: [
        'ext-openssl' => '*',
        'amphp/http-client' => '^5.3',
        'amphp/http-server' => '^3.4',
        'thesis/exceptionally' => '*',
        'thesis/testo-plugin' => '*',
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    testoPlugins: [new EventLoopPlugin()],
);

<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;
use Thesis\TestoPlugin\EventLoopPlugin;

return new Manifest(
    description: 'Asynchronous HTTP/1 client with pooled HTTP and HTTPS connections, proxy tunnels, middleware, '
        . 'redirects, retries, streaming decompression and multipart uploads.',
    licenseSince: 2026,
    version: '0.1',
    title: 'Thesis HTTP Client',
    keywords: [
        'php',
        'async',
        'http',
        'http1',
        'http-client',
        'streaming',
        'redirects',
        'retries',
        'middleware',
        'gzip',
        'multipart',
        'proxy',
        'keep-alive',
        'tcp',
        'tls',
    ],
    require: [
        'ext-zlib' => '*',
        'thesis/async' => '*',
        'thesis/http' => '*',
        'thesis/http-protocol' => '*',
        'thesis/io' => '*',
        'thesis/net' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
    testoPlugins: [new EventLoopPlugin()],
);

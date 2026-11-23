<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'HTTP messages shared by clients and servers: requests with query parameters, responses, headers, streamed bodies '
        . 'with trailers, and a strict HTTP/1.1 codec that refuses what request smuggling is made of.',
    licenseSince: 2026,
    version: '0.1',
    title: 'Thesis HTTP',
    keywords: [
        'php',
        'async',
        'http',
        'http-message',
        'http-headers',
        'query-string',
        'http-trailers',
        'http1',
        'http-parser',
        'chunked-encoding',
        'request-smuggling',
    ],
    require: [
        // The length of a body is a native int: Content-Length goes above 2^31.
        'php-64bit' => '*',
        'thesis/io' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'amphp/http-server' => '^3.4',
        'amphp/http-client' => '^5.3',
    ],
);

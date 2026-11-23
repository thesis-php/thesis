<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'HTTP wire protocols for clients and servers, with a strict HTTP/1 codec for heads, bodies and trailers.',
    licenseSince: 2026,
    version: '0.1',
    title: 'Thesis HTTP Protocol',
    keywords: ['php', 'http', 'http-protocol', 'http1', 'http-parser', 'chunked-encoding', 'http-trailers', 'request-smuggling'],
    require: [
        // Content-Length and chunk sizes can exceed 2^31.
        'php-64bit' => '*',
        'thesis/http' => '*',
        'thesis/io' => '*',
    ],
    requireDev: [
        'amphp/http-client' => '^5.3',
        'amphp/http-server' => '^3.4',
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
);

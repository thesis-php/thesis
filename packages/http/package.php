<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'HTTP messages shared by clients and servers: requests with query parameters, responses, headers, '
        . 'and streamed bodies with trailers.',
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
    ],
    require: [
        'thesis/io' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
);

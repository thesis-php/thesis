<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Where in the source code: a file and a line, the caller outside a library, the code of eval() resolved.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'source-location', 'backtrace', 'debugging'],
    require: [
        // Location::$shortFile is a property hook of PHP 8.4, and only since 8.4 the name of a closure
        // says where it is declared (Caller::$callee).
        'php' => '^8.4',
        'composer-runtime-api' => '^2.1',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
);

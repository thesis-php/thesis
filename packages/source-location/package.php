<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Where in the source code: a file and a line, the caller outside a library, the code of eval() resolved.',
    licenseSince: 2026,
    version: '0.1',
    require: [
        'composer-runtime-api' => '^2.1',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
);

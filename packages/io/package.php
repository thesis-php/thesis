<?php

declare(strict_types=1);

use Thesis\Monorepo\Layer;
use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Fast binary I/O: byte buffer with typed big/little-endian encoding, reader and writer interfaces.',
    layer: Layer::Io,
    require: [
        'php-64bit' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/lifecycle' => '^0.1.9',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
);

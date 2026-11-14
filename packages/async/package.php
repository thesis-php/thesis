<?php

declare(strict_types=1);

use Thesis\Monorepo\Layer;
use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Structured concurrency for PHP fibers: cancellation is a property of the task tree.',
    layer: Layer::Runtime,
    require: [
        'revolt/event-loop' => '^1.0.9',
    ],
    requireDev: [
        'ext-pcntl' => '*',
        'ext-posix' => '*',
        'amphp/amp' => '^3',
        'internal/container' => '^1.1',
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    files: ['src/autoload.files.php'],
    devFiles: ['tests/autoload.files.php'],
    suggest: [
        'ext-pcntl' => 'To use awaitSignal() and trapSignals() with the default event loop driver (ext-ev and ext-uv handle signals too)',
    ],
    conflict: [
        'amphp/amp' => '<3',
    ],
);

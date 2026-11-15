<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Functions for Time\Duration of PHP 8.6 and a polyfill of it for PHP 8.5, PSR-20 clocks.',
    licenseSince: 2026,
    require: [
        'php-64bit' => '*',
        'psr/clock' => '^1.0',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    files: ['src/autoload.files.php'],
    devFiles: ['tests/autoload.files.php'],
);

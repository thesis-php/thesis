<?php

declare(strict_types=1);

use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Thesis\Monorepo\Manifest;
use Thesis\Monorepo\RectorSkip;

return new Manifest(
    description: 'Formats PHP values, classes, functions and parameters into short strings for error messages.',
    licenseSince: 2026,
    version: '0.2',
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    skip: [
        new RectorSkip(
            rule: ArrayToFirstClassCallableRector::class,
            paths: ['tests/FormatCodeTest.php'],
            reason: 'The tests format a callable given as an array, a first-class callable is another case.',
        ),
    ],
);

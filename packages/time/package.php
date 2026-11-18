<?php

declare(strict_types=1);

use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Thesis\Monorepo\FixerSkip;
use Thesis\Monorepo\Manifest;
use Thesis\Monorepo\RectorSkip;

return new Manifest(
    description: 'Functions for Time\Duration of PHP 8.6 and a polyfill of it for PHP 8.2 to 8.5, 64-bit and 32-bit, '
        . 'PSR-20 clocks.',
    licenseSince: 2026,
    version: '0.3',
    keywords: ['php', 'time', 'duration', 'clock', 'psr-20', 'polyfill'],
    require: [
        // The polyfill runs where the rest of the monorepo does not: PHP 8.2 of Debian 12, the main 32-bit system.
        'php' => '^8.2',
        'psr/clock' => '^1.0',
        // \RoundingMode of PHP 8.4, which the functions take.
        'symfony/polyfill-php84' => '^1.34',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    skip: [
        new FixerSkip(
            paths: ['polyfill/TimeException.php'],
            reason: 'final_class has no exceptions, and Time\TimeException of PHP 8.6 is not final.',
        ),
        new RectorSkip(
            rule: AddTypeToConstRector::class,
            paths: ['src', 'polyfill', 'tests'],
            reason: 'The package runs on PHP 8.2, which has no types of class constants.',
        ),
    ],
    dist: ['polyfill'],
);

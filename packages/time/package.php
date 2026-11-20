<?php

declare(strict_types=1);

use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Thesis\Monorepo\FixerSkip;
use Thesis\Monorepo\Manifest;
use Thesis\Monorepo\RectorSkip;

return new Manifest(
    description: 'Time\Duration polyfill for PHP >=8.2, shorthands like sec(5) and toMs(), strings like 1h30m, PSR-20 clocks.',
    licenseSince: 2026,
    version: '0.5',
    keywords: ['php', 'time', 'duration', 'clock', 'psr-20', 'polyfill'],
    require: [
        // The polyfill runs where the rest of the monorepo does not: PHP 8.2 of Debian 12, the main 32-bit system.
        // 8.2.2: before it modify('@...') of shift() moved a time by its UTC offset (php-src GH-9891).
        'php' => '^8.2.2',
        'psr/clock' => '^1.0',
        // \RoundingMode of PHP 8.4, which the functions take.
        'symfony/polyfill-php84' => '^1.34',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.60',
    ],
    files: ['polyfill/load.php'],
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
    bit32Testing: true,
);

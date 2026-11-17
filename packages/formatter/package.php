<?php

declare(strict_types=1);

use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Thesis\Formatter\Internal\StringFormatter;
use Thesis\Monorepo\InfectionSkip;
use Thesis\Monorepo\Manifest;
use Thesis\Monorepo\RectorSkip;

return new Manifest(
    description: 'Formats PHP values, classes, functions and parameters into short strings for error messages.',
    licenseSince: 2026,
    version: '0.2',
    keywords: ['php', 'formatter', 'error-messages'],
    require: [
        'thesis/source-location' => '*',
    ],
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
        new InfectionSkip(
            mutator: 'IncrementInteger',
            class: StringFormatter::class,
            method: 'head',
            reason: 'More bytes than the characters may take are split for nothing: only slower.',
        ),
        new InfectionSkip(
            mutator: 'UnwrapSubstr',
            class: StringFormatter::class,
            method: 'head',
            reason: 'The whole string is split for nothing: only slower.',
        ),
        new InfectionSkip(
            mutator: 'Coalesce',
            class: StringFormatter::class,
            method: 'format',
            reason: 'A character that double quotes escape shows as itself, so it has no escape of its own to swap with.',
        ),
        new InfectionSkip(
            mutator: 'DecrementInteger',
            class: StringFormatter::class,
            method: 'codePoint',
            reason: 'The mask only clears the length prefix: its lowest bit is shifted out, and the bit after the prefix is 0 in a well-formed character.',
        ),
    ],
);

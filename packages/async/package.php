<?php

declare(strict_types=1);

use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Thesis\Monorepo\Manifest;
use Thesis\Monorepo\RectorSkip;

return new Manifest(
    description: 'Structured concurrency for PHP fibers: cancellation is a property of the task tree.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'fibers', 'structured-concurrency', 'cancellation', 'event-loop', 'revolt'],
    require: [
        'revolt/event-loop' => '^1.0.9',
        'thesis/dispatcher' => '*',
        'thesis/source-location' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'ext-pcntl' => '*',
        'ext-posix' => '*',
        'amphp/amp' => '^3',
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
    suggest: [
        'ext-pcntl' => 'To use awaitSignal() and trapSignals() with the default event loop driver (ext-ev and ext-uv handle signals too)',
    ],
    conflict: [
        'amphp/amp' => '<3',
    ],
    // The amphp bridge: amphp/amp comes with the amphp library it is used with,
    // and its classes are loaded only when ampCancellable() is called.
    optional: ['amphp/amp'],
    skip: [
        new RectorSkip(
            rule: ClassPropertyAssignToConstructorPromotionRector::class,
            paths: ['src/LocalSemaphore.php'],
            reason: "The property is non-negative-int, the parameter is positive-int: promotion would lose the property's type.",
        ),
    ],
);

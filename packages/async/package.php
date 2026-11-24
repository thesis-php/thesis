<?php

declare(strict_types=1);

use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Thesis\Monorepo\Manifest;
use Thesis\Monorepo\RectorSkip;
use Thesis\TestoPlugin\VirtualTimePlugin;

return new Manifest(
    description: 'Structured concurrency for PHP fibers with its own event loop, signals, task-tree cancellation and lazy initialization.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'fibers', 'structured-concurrency', 'cancellation', 'event-loop', 'lazy-initialization', 'signals'],
    require: [
        // Time is int nanoseconds of hrtime() (Instant, timers), on 32-bit hrtime() is a float.
        'php-64bit' => '*',
        'thesis/dispatcher' => '*',
        'thesis/source-location' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'ext-pcntl' => '*',
        'ext-posix' => '*',
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
    suggest: [
        'ext-pcntl' => 'To use awaitSignal() and trapSignals()',
    ],
    // Only the signals need ext-pcntl.
    optional: ['ext-pcntl'],
    skip: [
        new RectorSkip(
            rule: ClassPropertyAssignToConstructorPromotionRector::class,
            paths: ['src/LocalSemaphore.php'],
            reason: "The property is non-negative-int, the parameter is positive-int: promotion would lose the property's type.",
        ),
    ],
    testoPlugins: [new VirtualTimePlugin()],
);

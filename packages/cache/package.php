<?php

declare(strict_types=1);

use Thesis\Cache\Internal\Expiries;
use Thesis\Cache\LocalCache;
use Thesis\Monorepo\InfectionSkip;
use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Cache interface with Time\Duration TTLs, a local LRU cache and stampede protection by a keyed mutex.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'cache', 'lru-cache', 'cache-stampede'],
    require: [
        'thesis/async' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/data' => '^0.1.10',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
        'thesis/testo-plugin' => '*',
    ],
    skip: [
        new InfectionSkip(
            mutators: ['LogicalAnd'],
            class: Expiries::class,
            method: 'compare',
            reason: 'The assert only narrows the types for PHPStan: the heap compares its own values, which are all Expiry.',
        ),
        new InfectionSkip(
            mutators: ['DecrementInteger', 'IncrementInteger', 'GreaterThan', 'GreaterThanNegotiation'],
            class: LocalCache::class,
            method: 'set',
            reason: 'The heap of expiries is rebuilt to free the memory of its dead nodes: when and whether it is rebuilt changes no entry.',
            code: 'if ($this->expiries->count() > 2 * $this->entriesWithTtl) {',
        ),
        new InfectionSkip(
            mutators: ['MethodCallRemoval'],
            class: LocalCache::class,
            method: 'set',
            reason: 'The heap of expiries is rebuilt to free the memory of its dead nodes: when and whether it is rebuilt changes no entry.',
            code: '$this->rebuildExpiries();',
        ),
        new InfectionSkip(
            mutators: ['NotIdentical'],
            class: LocalCache::class,
            method: 'link',
            reason: 'The number of entries with a TTL only decides when the heap of expiries is rebuilt, see above.',
            code: 'if ($entry->expiresAt !== null) {',
        ),
        new InfectionSkip(
            mutators: ['Increment'],
            class: LocalCache::class,
            method: 'link',
            reason: 'The number of entries with a TTL only decides when the heap of expiries is rebuilt, see above.',
            code: '++$this->entriesWithTtl;',
        ),
        new InfectionSkip(
            mutators: ['Decrement'],
            class: LocalCache::class,
            method: 'unlink',
            reason: 'The number of entries with a TTL only decides when the heap of expiries is rebuilt, see above.',
            code: '--$this->entriesWithTtl;',
        ),
    ],
);

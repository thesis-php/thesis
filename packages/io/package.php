<?php

declare(strict_types=1);

use Thesis\Io\Buffer;
use Thesis\Io\Stream;
use Thesis\Monorepo\InfectionSkip;
use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'Fast binary I/O: byte buffer with typed encoding, non-blocking streams and stdio.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'fibers', 'io', 'non-blocking-io', 'streams', 'buffer', 'binary'],
    require: [
        'revolt/event-loop' => '^1.0.9',
        'thesis/async' => '*',
        'thesis/time' => '*',
    ],
    requireDev: [
        'testo/assert' => '^0.1.17',
        'testo/codecov' => '^0.2.5',
        'testo/data' => '^0.1.10',
        'testo/lifecycle' => '^0.1.9',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    skip: [
        new InfectionSkip(
            mutators: ['LessThan'],
            class: Buffer::class,
            method: 'readUvarint',
            reason: 'The shift grows by 7 and never equals 64, so < 64 and <= 64 stop the loop at once.',
            code: 'for ($shift = 7; $shift < 64; $shift += 7) {',
        ),
        new InfectionSkip(
            mutators: ['IncrementInteger'],
            class: Buffer::class,
            method: 'writeVarint',
            reason: 'PHP shifts right by 64 and more as by 63: the sign fills every bit.',
            code: 'return $this->writeUvarint($value << 1 ^ $value >> 63);',
        ),
        new InfectionSkip(
            mutators: ['MethodCallRemoval'],
            class: Buffer::class,
            method: 'fill',
            reason: 'Compaction only frees the memory of the bytes read: the unread bytes are the same with it and without.',
            code: '$this->compact();',
        ),
        new InfectionSkip(
            mutators: ['GreaterThanOrEqualTo', 'GreaterThanOrEqualToNegotiation'],
            class: Buffer::class,
            method: 'compact',
            reason: 'Compaction only frees the memory of the bytes read: the unread bytes are the same with it and without.',
            code: '} elseif ($this->offset >= self::COMPACT_THRESHOLD) {',
        ),
        new InfectionSkip(
            mutators: ['Plus', 'DecrementInteger'],
            class: Buffer::class,
            method: 'fillUntil',
            reason: 'The tail kept for a delimiter split between chunks may be longer: the delimiter is found anyway, by a longer search.',
            code: '$scanned = max(0, $available - \strlen($delimiter) + 1);',
        ),
        new InfectionSkip(
            mutators: ['FunctionCallRemoval'],
            class: Stream::class,
            method: 'read',
            reason: 'A failed fread() or fwrite() of a stream of the OS reports its own warning, which replaces the last error.',
            code: 'error_clear_last();',
        ),
        new InfectionSkip(
            mutators: ['ReturnRemoval'],
            class: Stream::class,
            method: 'write',
            reason: 'Without the return a write that the fast path has finished takes the lock and writes nothing more.',
            code: 'return;',
        ),
        new InfectionSkip(
            mutators: ['Throw_'],
            class: Stream::class,
            method: 'finishWrite',
            reason: 'The watcher ends a wait with false only after close(), so the loop would throw the same StreamClosed at its start: the throw says it where the wait ends, as in read().',
            code: "throw new StreamClosed('The stream was closed while writing.');",
        ),
        new InfectionSkip(
            mutators: ['Increment'],
            class: Stream::class,
            method: 'finishWrite',
            reason: 'The number of writers only decides whether a write may take the fast path, which skips the lock while there are no other writers: without it a write takes the lock, slower, with the same bytes in the same order.',
            code: '++$this->writers;',
        ),
        new InfectionSkip(
            mutators: ['Decrement', 'Finally_', 'UnwrapFinally'],
            class: Stream::class,
            method: 'finishWrite',
            reason: 'The number of writers only decides whether a write may take the fast path, which skips the lock while there are no other writers: without it a write takes the lock, slower, with the same bytes in the same order.',
            code: '--$this->writers;',
        ),
    ],
);

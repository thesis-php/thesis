<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Io\Poll\Watcher;

/**
 * The waits of {@see Loop} on one stream: `Io\Poll` takes a stream once per context, so its readers and writers
 * share one watcher, whose events are the union of what they wait for.
 *
 * @internal
 */
final class WatchedStream
{
    /**
     * @var array<int, Waiter> by spl_object_id()
     */
    public array $readers = [];

    /**
     * @var array<int, Waiter> by spl_object_id()
     */
    public array $writers = [];

    /**
     * null until the loop has added the stream to its context, and after it has removed it.
     */
    public ?Watcher $watcher = null;

    /**
     * Whether the waits have changed since the loop last brought the watcher up to date.
     */
    public bool $changed = false;

    /**
     * @param resource $stream
     */
    public function __construct(
        public readonly mixed $stream,
    ) {}
}

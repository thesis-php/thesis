<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;

/**
 * Something that happened to a scope of the tree: a group, a task or a daemon, a timeout() or a shield().
 * Only the library creates events, and the list below is all of them: a tool may handle each one,
 * and PHPStan tells it when a new one comes.
 *
 * @api
 * @phpstan-sealed GroupCreated|TaskCreated|TimeoutCreated|ShieldCreated|TaskStarted|Suspended|Resumed|ScopeCancelled|Joining|Ended
 */
interface Event
{
    /**
     * The scope it happened to: numbered from 1 in the order the observation sees them first,
     * so a trace on virtual time is the same on every run.
     *
     * @var positive-int
     */
    public int $scope { get; }

    public Instant $at { get; }
}

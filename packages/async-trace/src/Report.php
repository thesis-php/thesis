<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Instant;

/**
 * What a tool of the trace has found, dispatched by the tool to the dispatcher of the trace that holds it.
 * A report holds only what is formatted already: a listener may keep it as long as it likes.
 * A listener of the reports runs inside a listener of `observe()`: it must not throw or suspend the fiber.
 *
 * @api
 * @phpstan-sealed CancelledBy
 */
interface Report extends \Stringable
{
    /**
     * The scope it is about, numbered as in the events of the trace.
     *
     * @var positive-int
     */
    public int $scope { get; }

    public Instant $at { get; }
}

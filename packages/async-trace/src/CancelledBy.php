<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Instant;
use Thesis\Async\Trace\CancelCause;

/**
 * A scope has ended as cancelled, and this is what has cancelled it:
 * `#3 task 'fetch' at /app/src/main.php:12 is cancelled by the deadline of timeout 2s at /app/src/main.php:10`.
 *
 * @api
 */
final readonly class CancelledBy implements Report
{
    /**
     * @param positive-int $scope
     * @param non-empty-string $what the scope and where it is created: `task 'fetch' at /app/src/main.php:12`
     * @param Instant $at the moment of the cancellation, not of the end
     * @param ?positive-int $by the scope that has cancelled, as `ScopeCancelled::$by`
     * @param non-empty-string $reason what has cancelled: `the deadline of timeout 2s at /app/src/main.php:10`,
     *     `Thesis\Async\Task::cancel() at /app/src/main.php:20 in group at /app/src/main.php:8`,
     *     `the failure of task 'fetch' at /app/src/main.php:12: RuntimeException 'boom' at /app/src/Api.php:40`
     */
    public function __construct(
        public int $scope,
        public string $what,
        public Instant $at,
        public CancelCause $cause,
        public ?int $by,
        public string $reason,
    ) {}

    public function __toString(): string
    {
        return "#{$this->scope} {$this->what} is cancelled by {$this->reason}";
    }
}

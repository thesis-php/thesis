<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Instant;
use Thesis\AsyncTrace\Internal\Seconds;
use Time\Duration;

/**
 * Code of a scope has run longer than the threshold without giving up control, and no other task has run meanwhile:
 * `#2 task 'work' at /app/src/main.php:8 has run 0.12s without a wait: from Thesis\Async\delay()
 * at /app/src/main.php:9 to Thesis\Async\Channel::receive() at /app/src/main.php:14`.
 *
 * @api
 */
final readonly class Blocked implements Report
{
    /**
     * @param positive-int $scope the scope whose code has woken up and run
     * @param non-empty-string $what the scope and where it is created: `task 'work' at /app/src/main.php:8`
     * @param Instant $at when the run has begun
     * @param non-empty-string $from where the run has begun: the wait it has woken up from,
     *     `Thesis\Async\delay() at /app/src/main.php:9`, or `the start of the task`
     * @param non-empty-string $to where it has given up control: the next wait, `the join of the group`
     *     or `the end of the task`
     */
    public function __construct(
        public int $scope,
        public string $what,
        public Instant $at,
        public Duration $duration,
        public string $from,
        public string $to,
    ) {}

    public function __toString(): string
    {
        return \sprintf(
            '#%d %s has run %ss without a wait: from %s to %s',
            $this->scope,
            $this->what,
            Seconds::format($this->duration),
            $this->from,
            $this->to,
        );
    }
}

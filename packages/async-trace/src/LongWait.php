<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Instant;
use Thesis\AsyncTrace\Internal\Seconds;
use Time\Duration;

/**
 * Code of a scope has been waiting for the threshold, and still waits:
 * `#2 task 'fetch' at /app/src/main.php:12 has been waiting 5s in Thesis\Async\Channel::receive()
 * at /app/src/main.php:14`.
 *
 * @api
 */
final readonly class LongWait implements Report
{
    /**
     * @param positive-int $scope the scope that waits
     * @param non-empty-string $what the scope and where it is created: `task 'fetch' at /app/src/main.php:12`
     * @param Instant $at when the wait has begun
     * @param Duration $duration how long it has waited when reported: the threshold, or a hair more
     *     on the real clock
     * @param non-empty-string $wait the call that waits: `Thesis\Async\Channel::receive() at /app/src/main.php:14`
     */
    public function __construct(
        public int $scope,
        public string $what,
        public Instant $at,
        public Duration $duration,
        public string $wait,
    ) {}

    public function __toString(): string
    {
        return \sprintf(
            '#%d %s has been waiting %ss in %s',
            $this->scope,
            $this->what,
            Seconds::format($this->duration),
            $this->wait,
        );
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Logger;

use Psr\Log\LogLevel;

/**
 * The snapshot of a PSR-3 call passed to a formatter. It carries the level, message and normalized context.
 *
 * @api
 * @see https://www.php-fig.org/psr/psr-3/#1-specification
 * @phpstan-type Context array<array-key, scalar|null|array{type: string, message: string, file: string, line: int}>
 */
final readonly class Record
{
    /**
     * @param \DateTimeImmutable $time the time supplied by the logger's clock when the log call was made
     * @param LogLevel::* $level the PSR-3 level
     * @param string $message the message after placeholder interpolation
     * @param Context $context the normalized values, including those used in the message
     * @param list<array-key> $interpolatedKeys the context keys substituted into the message
     */
    public function __construct(
        public \DateTimeImmutable $time,
        public string $level,
        public string $message,
        public array $context,
        public array $interpolatedKeys = [],
    ) {}
}

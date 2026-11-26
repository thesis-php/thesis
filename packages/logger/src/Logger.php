<?php

declare(strict_types=1);

namespace Thesis;

use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Thesis\Io\Writer;
use Thesis\Logger\Handler;
use Thesis\Logger\Internal\RecordFactory;
use Thesis\Logger\StreamHandler;
use Thesis\Time\WallClock;

/**
 * Writes PSR-3 records through the supplied handler or directly to a writer.
 * Event levels follow the severity order in RFC 5424.
 *
 * @api
 * @see https://www.php-fig.org/psr/psr-3/
 * @see https://www.php-fig.org/psr/psr-20/
 * @see https://www.rfc-editor.org/rfc/rfc5424.html#section-6.2.1
 */
final class Logger extends AbstractLogger
{
    private const array PRIORITIES = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly Handler $handler;

    private readonly int $minPriority;

    public function __construct(
        Writer|Handler $output,
        string $minLevel = LogLevel::INFO,
        private readonly ClockInterface $clock = new WallClock(),
    ) {
        $this->handler = $output instanceof Handler ? $output : new StreamHandler($output);
        $this->minPriority = self::PRIORITIES[self::checkedLevel($minLevel)];
    }

    /**
     * @param array<array-key, mixed> $context
     * @throws InvalidArgumentException if the level is unknown
     */
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $level = self::checkedLevel($level);

        if (self::PRIORITIES[$level] < $this->minPriority) {
            return;
        }

        $this->handler->handle(fn() => RecordFactory::capture($this->clock->now(), $level, $message, $context));
    }

    /**
     * @return key-of<self::PRIORITIES>
     * @throws InvalidArgumentException if the level is unknown
     */
    private static function checkedLevel(mixed $level): string
    {
        if (!\is_string($level) || !isset(self::PRIORITIES[$level])) {
            throw new InvalidArgumentException('Unknown log level.');
        }

        return $level;
    }
}

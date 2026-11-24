<?php

declare(strict_types=1);

namespace Thesis;

use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Thesis\Async\Cancelled;
use Thesis\Async\Channel;
use Thesis\Async\Group;
use Thesis\Async\ScopedValue;
use Thesis\Async\SendResult;
use Thesis\Async\Service;
use Thesis\Async\Task;
use Thesis\Async\TaskCancelled;
use Thesis\Io\Writer;
use Thesis\Logger\Formatter;
use Thesis\Logger\Formatter\Json;
use Thesis\Logger\Internal\RecordFactory;
use Thesis\Logger\Record;
use Thesis\Time\WallClock;

/**
 * Writes PSR-3 records through a background task. Logging never waits for room or for the writer.
 * Event levels follow the severity order in RFC 5424.
 *
 * @api
 * @see https://www.php-fig.org/psr/psr-3/
 * @see https://www.php-fig.org/psr/psr-20/
 * @see https://www.rfc-editor.org/rfc/rfc5424.html#section-6.2.1
 */
final class Logger extends AbstractLogger implements Service
{
    private const int MAX_LINE_BYTES = 16 * 1024;
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

    /**
     * @var non-negative-int records lost or refused, excluding records filtered by level
     */
    public private(set) int $dropped = 0;

    /**
     * The formatting or output error that stopped the writer, available before stop().
     */
    public private(set) ?\Throwable $failure = null;

    /**
     * @var Channel<Record>
     */
    private readonly Channel $queue;

    /**
     * @var ?Task<?\Throwable>
     */
    private ?Task $task = null;

    /**
     * @var ScopedValue<bool>
     */
    private readonly ScopedValue $writing;

    private readonly int $minPriority;

    /**
     * @param Writer $output the borrowed destination for formatted log lines
     * @param string $minLevel the lowest PSR-3 level to record
     * @param positive-int $capacity the maximum number of records waiting in the queue
     * @param Formatter $formatter formats records in the writer task
     * @param ClockInterface $clock supplies the timestamp in the calling fiber
     * @throws InvalidArgumentException if the minimum level is unknown
     */
    public function __construct(
        private readonly Writer $output,
        string $minLevel = LogLevel::INFO,
        int $capacity = 1024,
        private readonly Formatter $formatter = new Json(),
        private readonly ClockInterface $clock = new WallClock(),
    ) {
        $this->minPriority = self::PRIORITIES[self::checkedLevel($minLevel)];
        $this->queue = new Channel($capacity);
        $this->writing = new ScopedValue(false);
    }

    private function __clone() {}

    /**
     * Starts the writer in the supplied group. Call {@see stop()} while the group can still run the writer.
     *
     * @throws \LogicException if the logger has already started or closed, or the group has ended
     */
    public function start(Group $group): void
    {
        if ($this->task !== null || $this->queue->isClosed) {
            throw new \LogicException('The logger has already started or closed.');
        }

        $this->task = $this->writing->with(true, fn() => $group->daemon($this->write(...), name: 'write logs'));
    }

    /**
     * Records a message without waiting for the writer. The configured clock and a Stringable message's
     * conversion run in the calling fiber.
     * Before {@see start()}, after close, from its own writer or when the queue is full, counts the record as dropped.
     * Unsupported context values are summarized without calling user code.
     *
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

        if (!$this->hasActiveWriter() || $this->queue->isClosed || $this->writing->get()) {
            ++$this->dropped;

            return;
        }

        $record = RecordFactory::capture($this->clock->now(), $level, $message, $context);

        // A clock or message conversion can suspend while the writer finishes.
        if (!$this->hasActiveWriter() || $this->queue->trySend($record) !== SendResult::Sent) {
            ++$this->dropped;
        }
    }

    private function hasActiveWriter(): bool
    {
        return $this->task !== null && !$this->task->isDone;
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

    /**
     * Stops accepting records and waits for the writer to drain the queue. If the wait is cancelled,
     * the writer can continue and another call can wait for it. The output stays open.
     *
     * @throws \Throwable if formatting or writing a record failed
     * @throws TaskCancelled if the writer task was cancelled
     */
    public function stop(): void
    {
        $this->queue->close();

        try {
            $failure = $this->task?->await();

            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            // A daemon cancelled before it starts has not run its cleanup.
            if ($this->task?->isDone === true) {
                $this->discardPending();
            }
        }
    }

    private function write(): ?\Throwable
    {
        $inFlight = false;

        try {
            foreach ($this->queue as $record) {
                $inFlight = true;
                $line = $this->formatter->format($record);
                $length = \strlen($line);

                if ($length >= self::MAX_LINE_BYTES || strcspn($line, "\r\n") !== $length) {
                    ++$this->dropped;
                } else {
                    $this->output->write($line . "\n");
                }

                $inFlight = false;
            }

            return null;
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable $failure) {
            return $this->failure = $failure;
        } finally {
            if ($inFlight) {
                ++$this->dropped;
            }

            // Trace listeners can log after this task returns, before its handle is marked done.
            $this->queue->close();
            $this->discardPending();
        }
    }

    private function discardPending(): void
    {
        while ($this->queue->tryReceive() !== null) {
            ++$this->dropped;
        }
    }
}

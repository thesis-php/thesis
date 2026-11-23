<?php

declare(strict_types=1);

namespace Thesis;

use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\TaskCancelled;
use Thesis\Async\Trace\Ended;
use Thesis\Io\WriteFailed;
use Thesis\Io\Writer;
use Thesis\Logger\Formatter\Json;
use Thesis\Logger\Internal\MessageInterpolator;
use Thesis\Logger\Internal\RecordFactory;
use Thesis\Logger\Record;
use Thesis\Logger\Stub\CallbackFormatter;
use Thesis\Logger\Stub\RecordingWriter;
use Thesis\Time\FrozenClock;
use function Thesis\Async\checkpoint;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\observe;
use function Thesis\Logger\thrown;
use function Thesis\Time\ms;

#[Test]
#[Covers(Logger::class)]
#[Covers(Record::class)]
#[Covers(RecordFactory::class)]
#[Covers(MessageInterpolator::class)]
#[Covers(Json::class)]
final class LoggerTest
{
    public function filtersLevelsAndDrainsTheQueueInOrder(): void
    {
        $output = new RecordingWriter();
        $formatter = new CallbackFormatter(static fn(Record $record) => $record->message);
        $logger = new Logger($output, capacity: 2, formatter: $formatter);
        $logger->info('Before start');

        group(static function (Group $group) use ($logger, $output) {
            $logger->run($group);
            $logger->debug('Below minimum');
            $logger->info('First');
            $logger->warning('Second');
            $logger->error('Queue full');

            Assert::same($output->writes, []);
            Assert::same($logger->dropped, 2);

            $logger->close();
            $logger->close();
            $logger->info('After close');

            Assert::same($output->writes, ["First\n", "Second\n"]);
            Assert::same($logger->dropped, 3);
        });
    }

    public function interpolatesOnceAndPreservesStructuredContext(): void
    {
        $output = new RecordingWriter();
        $logger = new Logger($output);

        group(static function (Group $group) use ($logger) {
            $logger->run($group);
            $logger->info('{exception} {} {two words} {http.status} {ok} {peer} {template} {undefined} {9} {done}', [
                'exception' => new \RuntimeException('This is structured context'),
                '' => 'Skipped',
                'two words' => 'Skipped',
                'http.status' => 503,
                'ok' => false,
                'peer' => null,
                'template' => '{http.status}',
                9 => 'Ninth',
                'done' => true,
            ]);
            $logger->close();
        });

        Assert::string($output->writes[0] ?? '')->endsWith("\n");
        Assert
            ::json($output->writes[0] ?? '')
            ->assertPath('$.level', static fn($level) => Assert::same($level->decode(), 'info'))
            ->assertPath('$.message', static fn($message) => Assert::same(
                $message->decode(),
                '{exception} {} {two words} 503 false null {http.status} {undefined} Ninth true',
            ))
            ->assertPath('$.context["http.status"]', static fn($status) => Assert::same($status->decode(), 503))
        ;
    }

    public function snapshotsContextBeforeTheFormatterRuns(): void
    {
        /** @var Deferred<Record> $captured */
        $captured = new Deferred();
        $formatter = new CallbackFormatter(static function (Record $record) use ($captured) {
            $captured->complete($record);

            return 'Done';
        });
        $contextValue = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                throw new \LogicException('Logging should not call user code.');
            }
        };
        $logger = new Logger(new RecordingWriter(), formatter: $formatter);
        $logger->error($contextValue);

        group(static function (Group $group) use ($logger, $contextValue) {
            $logger->run($group);
            $logger->debug($contextValue);
            $timezone = date_default_timezone_get();
            date_default_timezone_set('Asia/Tokyo');

            try {
                $logger->log(LogLevel::ERROR, 'Connection lost', [
                    'exception' => new \RuntimeException('Connection lost'),
                    'value' => $contextValue,
                    'array' => [1, 2],
                    'number' => INF,
                    "\0hidden" => true,
                    str_repeat('x', 129) => true,
                    'long' => str_repeat('a', 1100),
                ]);
            } finally {
                date_default_timezone_set($timezone);
            }

            $logger->close();
        });
        $logger->error($contextValue);

        $record = $captured->await();
        Assert::same($logger->dropped, 2);
        Assert::instanceOf($record, Record::class);
        Assert::same($record->time->getTimezone()->getName(), 'Asia/Tokyo');
        Assert::same($record->message, 'Connection lost');
        Assert::same($record->context, [
            'exception' => ['type' => \RuntimeException::class, 'message' => 'Connection lost'],
            'value' => '[' . get_debug_type($contextValue) . ']',
            'array' => '[array]',
            'number' => null,
            'long' => str_repeat('a', 1024),
        ]);
    }

    public function rendersStringableMessagesLikeMonologWithPsr3Interpolation(): void
    {
        $referenceOutput = new TestHandler();
        $reference = new MonologLogger('reference', [$referenceOutput], [new PsrLogMessageProcessor()]);
        $output = new RecordingWriter();
        $logger = new Logger($output);
        $message = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'HTTP returned {status} for {method}, {missing}';
            }
        };
        $context = ['status' => 503, 'method' => 'POST'];

        $reference->warning($message, $context);
        group(static function (Group $group) use ($logger, $message, $context) {
            $logger->run($group);
            $logger->warning($message, $context);
            $logger->close();
        });

        $expected = $referenceOutput->getRecords()[0] ?? throw new \LogicException('Monolog did not write the record.');
        $level = $expected->level->toPsrLogLevel();
        $fields = $expected->context;

        Assert
            ::json($output->writes[0] ?? '')
            ->assertPath('$.message', static fn($value) => Assert::same($value->decode(), $expected->message))
            ->assertPath('$.level', static fn($value) => Assert::same($value->decode(), $level))
            ->assertPath('$.context.status', static fn($value) => Assert::same(
                $value->decode(),
                $fields['status'] ?? null,
            ))
            ->assertPath('$.context.method', static fn($value) => Assert::same(
                $value->decode(),
                $fields['method'] ?? null,
            ))
        ;
    }

    public function capturesTheConfiguredClockTimeBeforeTheWriterRuns(): void
    {
        $output = new RecordingWriter();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-23T22:12:00.012345+03:00'));
        $logger = new Logger($output, clock: $clock);

        group(static function (Group $group) use ($logger, $clock) {
            $logger->run($group);
            $logger->info('First');
            $clock->now = new \DateTimeImmutable('2026-11-23T14:13:00.012345-05:00');
            $logger->info('Second');
            $logger->close();
        });

        Assert::string($output->writes[0] ?? '')->startsWith('{"time":"2026-11-23T22:12:00.012345+03:00",');
        Assert::string($output->writes[1] ?? '')->startsWith('{"time":"2026-11-23T14:13:00.012345-05:00",');
    }

    public function rejectsUnknownLevels(): void
    {
        $logger = new Logger(new RecordingWriter());

        Assert::instanceOf(thrown(static fn() => $logger->log(4, 'Message')), InvalidArgumentException::class);
        Assert::instanceOf(thrown(static fn() => $logger->log('verbose', 'Message')), InvalidArgumentException::class);

        Expect::exception(InvalidArgumentException::class);
        new Logger(new RecordingWriter(), minLevel: 'verbose');
    }

    public function boundsTextAndKeepsTheFirstThirtyTwoFields(): void
    {
        $captured = [];
        $formatter = new CallbackFormatter(static function (Record $record) use (&$captured) {
            $captured[] = $record;

            return 'Done';
        });
        $logger = new Logger(new RecordingWriter(), formatter: $formatter);
        $long = str_repeat('a', 1100);
        $key = str_repeat('k', 128);

        group(static function (Group $group) use ($logger, $long, $key) {
            $logger->run($group);
            $logger->info(str_repeat('a', 1020) . '{field}', [
                $key => 'Kept',
                'field' => 'Value',
            ] + array_fill(1, 31, true));
            $logger->info('{message}{message}', ['message' => $long, 'exception' => new \RuntimeException($long)]);
            $logger->info('Summarized', ['exception' => 'Reason', 'other' => new \RuntimeException('Text')]);
            $logger->close();
        });

        Assert::count($captured, 3);
        $first = $captured[0] ?? throw new \LogicException('The first record was not formatted.');
        $second = $captured[1] ?? throw new \LogicException('The second record was not formatted.');
        $third = $captured[2] ?? throw new \LogicException('The third record was not formatted.');
        $clipped = str_repeat('a', 1024);
        Assert::same($first->message, str_repeat('a', 1020) . '{fie');
        Assert::same($first->context, [$key => 'Kept', 'field' => 'Value'] + array_fill(1, 30, true));
        Assert::same($second->message, $clipped);
        $exception = ['type' => \RuntimeException::class, 'message' => $clipped];
        Assert::same($second->context['exception'] ?? null, $exception);
        Assert::same($third->context, ['exception' => 'Reason', 'other' => '[RuntimeException]']);
    }

    public function refusesToStartTwiceOrAfterClose(): void
    {
        $logger = new Logger(new RecordingWriter());

        group(static function (Group $group) use ($logger) {
            $logger->run($group);

            Assert::instanceOf(thrown(static fn() => $logger->run($group)), \LogicException::class);

            $logger->close();

            Assert::same($logger->dropped, 0);

            $closed = new Logger(new RecordingWriter());
            $closed->close();

            Assert::instanceOf(thrown(static fn() => $closed->run($group)), \LogicException::class);
        });
    }

    public function dropsInvalidLinesAndKeepsTheLargestValidLine(): void
    {
        $output = new RecordingWriter();
        $formatter = new CallbackFormatter(static fn(Record $record) => match ($record->message) {
            'Oversized' => str_repeat('a', 16 * 1024),
            'At limit' => str_repeat('a', 16 * 1024 - 1),
            'LF' => "First\nSecond",
            default => "First\rSecond",
        });
        $logger = new Logger($output, formatter: $formatter);

        group(static function (Group $group) use ($logger) {
            $logger->run($group);
            $logger->info('Oversized');
            $logger->info('LF');
            $logger->info('CR');
            $logger->info('At limit');
            $logger->close();
        });

        Assert::same($logger->dropped, 3);
        Assert::same($output->writes, [str_repeat('a', 16 * 1024 - 1) . "\n"]);
    }

    public function reportsFormatterFailureAndDiscardsPendingRecords(): void
    {
        $failure = new \RuntimeException('The formatter failed.');
        $failed = new Deferred();
        $formatter = new CallbackFormatter(static function () use ($failed, $failure) {
            $failed->complete();

            throw $failure;
        });
        $output = new RecordingWriter();
        $logger = new Logger($output, formatter: $formatter);
        $observer = new Dispatcher([static function (Ended $event) use ($logger, $failure) {
            if ($event->value === $failure) {
                $logger->info('The writer ended.');
            }
        }]);

        observe($observer, static fn() => group(static function (Group $group) use ($logger, $failure, $failed) {
            $logger->run($group);
            $logger->info('First');
            $logger->info('Second');
            $failed->await();

            Assert::same($logger->dropped, 3);
            Assert::same(thrown($logger->close(...)), $failure);
            Assert::same(thrown($logger->close(...)), $failure);
        }));

        Assert::same($output->writes, []);
    }

    public function reportsWriteFailureWhenClosing(): void
    {
        $output = new class implements Writer {
            #[\Override]
            public function write(string $bytes): void
            {
                throw new WriteFailed('Output unavailable.');
            }
        };
        $logger = new Logger($output);

        group(static function (Group $group) use ($logger) {
            $logger->run($group);
            $logger->info('First');

            Expect::exception(WriteFailed::class)->withMessage('Output unavailable.');
            $logger->close();
        });
    }

    public function dropsAStringableMessageIfTheWriterIsCancelledBeforeItStarts(): void
    {
        /** @var Deferred<null> $wasConverted */
        $wasConverted = new Deferred();
        $message = new readonly class ($wasConverted) implements \Stringable {
            /**
             * @param Deferred<null> $wasConverted
             */
            public function __construct(
                private Deferred $wasConverted,
            ) {}

            #[\Override]
            public function __toString(): string
            {
                $this->wasConverted->complete();
                checkpoint();

                return 'Late record';
            }
        };
        $logger = new Logger(new RecordingWriter());

        group(static function (Group $group) use ($logger, $message, $wasConverted) {
            $logger->run($group);
            $group->cancelTasks();
            $logger->info($message);

            Assert::true($wasConverted->isDone);
            Assert::same($logger->dropped, 1);
            Assert::instanceOf(thrown($logger->close(...)), TaskCancelled::class);
        });
    }

    public function cancellingCloseLeavesTheWriterAvailableToDrain(): void
    {
        $output = new RecordingWriter();
        $resume = new Deferred();
        $formatter = new CallbackFormatter(static function (Record $record) use ($resume) {
            $resume->await();

            return $record->message;
        });
        $logger = new Logger($output, formatter: $formatter);

        group(static function (Group $group) use ($logger, $resume, $output) {
            $logger->run($group);
            $logger->info('Done');
            $closed = timeoutOr(ms(1), static function () use ($logger) {
                $logger->close();

                return true;
            });

            Assert::null($closed);
            Assert::same($output->writes, []);

            $resume->complete();
            $logger->close();

            Assert::same($output->writes, ["Done\n"]);
            Assert::same($logger->dropped, 0);
        });
    }

    public function discardsRecordsWhenTheWriterNeverStarts(): void
    {
        $output = new RecordingWriter();
        $logger = new Logger($output);

        group(static function (Group $group) use ($logger) {
            $logger->run($group);
            $logger->info('Pending');
            $group->cancelTasks();
        });

        $logger->info('After cancellation');

        Assert::same($logger->dropped, 1);
        Assert::instanceOf(thrown($logger->close(...)), TaskCancelled::class);
        Assert::instanceOf(thrown($logger->close(...)), TaskCancelled::class);
        Assert::same($logger->dropped, 2);
        Assert::same($output->writes, []);
    }

    public function discardsTheInFlightRecordWhenTheWriterIsCancelled(): void
    {
        $started = new Deferred();
        $resume = new Deferred();
        $formatter = new CallbackFormatter(static function (Record $record) use ($started, $resume) {
            $started->complete();
            $resume->await();

            return $record->message;
        });
        $output = new RecordingWriter();
        $logger = new Logger($output, formatter: $formatter);

        group(static function (Group $group) use ($logger, $started) {
            $logger->run($group);
            $logger->info('Formatting');
            $started->await();
            $logger->info('Pending');
            $group->cancelTasks();

            Assert::instanceOf(thrown($logger->close(...)), TaskCancelled::class);
        });

        Assert::same($logger->dropped, 2);
        Assert::same($output->writes, []);
    }
}

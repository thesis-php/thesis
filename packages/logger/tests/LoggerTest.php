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
use Thesis\Io\WriteFailed;
use Thesis\Io\Writer;
use Thesis\Logger\Formatter\Console;
use Thesis\Logger\Formatter\Json;
use Thesis\Logger\Internal\MessageInterpolator;
use Thesis\Logger\Internal\RecordFactory;
use Thesis\Logger\Record;
use Thesis\Logger\StreamHandler;
use Thesis\Logger\Stub\CallbackFormatter;
use Thesis\Logger\Stub\RecordingWriter;
use Thesis\Time\FrozenClock;
use function Thesis\Logger\thrown;

#[Test]
#[Covers(Logger::class)]
#[Covers(StreamHandler::class)]
#[Covers(Record::class)]
#[Covers(RecordFactory::class)]
#[Covers(MessageInterpolator::class)]
#[Covers(Json::class)]
#[Covers(Console::class)]
final class LoggerTest
{
    public function dropsNestedCallsToTheSameStreamHandler(): void
    {
        $output = new RecordingWriter();
        $formatter = new CallbackFormatter(static function (Record $record) use (&$logger) {
            if ($record->message === 'Outer') {
                /** @var Logger $logger */
                $logger->info('Nested');
            }

            return $record->message;
        });
        $logger = new Logger(new StreamHandler($output, $formatter));

        $logger->info('Outer');
        $logger->info('Next');

        Assert::same($output->writes, ["Outer\n", "Next\n"]);
    }

    public function interpolatesOnceAndPreservesStructuredContext(): void
    {
        $output = new RecordingWriter();
        $logger = new Logger($output);

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

    public function consoleOmitsOnlyTheFieldsUsedInTheMessage(): void
    {
        $output = new RecordingWriter();
        $logger = new Logger(new StreamHandler($output, new Console(colors: false)));

        $logger->info('{0} {0}', ['{other}', 'other' => 42]);
        $logger->info('Ready {ok}', ['ok' => true]);

        Assert::string($output->writes[0] ?? '')->endsWith(" INFO {other} {other} {\"other\":42}\n");
        Assert::string($output->writes[1] ?? '')->endsWith(" INFO Ready true\n");
    }

    public function snapshotsContextBeforeTheFormatterRuns(): void
    {
        $record = null;
        $formatter = new CallbackFormatter(static function (Record $captured) use (&$record) {
            $record = $captured;

            return 'Done';
        });
        $contextValue = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                throw new \LogicException('Logging should not call user code.');
            }
        };
        $logger = new Logger(new StreamHandler(new RecordingWriter(), $formatter));

        $error = new \RuntimeException('Connection lost');
        $logger->debug($contextValue);
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $logger->log(LogLevel::ERROR, 'Connection lost', [
                'exception' => $error,
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

        Assert::instanceOf($record, Record::class);
        Assert::same($record->time->getTimezone()->getName(), 'Asia/Tokyo');
        Assert::same($record->message, 'Connection lost');
        Assert::same($record->context, [
            'exception' => [
                'type' => \RuntimeException::class,
                'message' => 'Connection lost',
                'file' => __FILE__,
                'line' => $error->getLine(),
            ],
            'value' => '[' . get_debug_type($contextValue) . ']',
            'array' => '[array]',
            'number' => null,
            'long' => str_repeat('a', 1024),
        ]);
    }

    public function snapshotsExceptionFieldsWithoutCallingUserCode(): void
    {
        $message = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \LogicException('The logger cast an exception message.');
            }
        };
        $exception = new class extends \Exception {
            protected string $file = '' {
                get => 'A hooked filename: ' . $this->file;
            }
        };
        $error = new \Error();
        new \ReflectionProperty($exception, 'message')->setRawValue($exception, $message);
        new \ReflectionProperty($error, 'message')->setRawValue($error, ['bad message']);
        $time = new \DateTimeImmutable();

        $first = RecordFactory::capture($time, LogLevel::ERROR, 'First', ['exception' => $exception]);
        $second = RecordFactory::capture($time, LogLevel::ERROR, 'Second', ['exception' => $error]);

        Assert::same($first->context['exception'] ?? null, [
            'type' => $exception::class,
            'message' => '[' . get_debug_type($message) . ']',
            'file' => __FILE__,
            'line' => $exception->getLine(),
        ]);
        Assert::same($second->context['exception'] ?? null, [
            'type' => \Error::class,
            'message' => '[array]',
            'file' => __FILE__,
            'line' => $error->getLine(),
        ]);
    }

    public function handlesUnsetExceptionFieldsWithoutCallingMagicGet(): void
    {
        $exception = new class extends \Exception {
            final protected $message = '';

            final protected string $file = '';

            final protected int $line = 0;

            public function __construct()
            {
                parent::__construct();
                unset($this->message, $this->file, $this->line);
            }

            public function __get(string $name): never
            {
                throw new \LogicException("The logger read unset property {$name}.");
            }
        };

        $record = RecordFactory::capture(
            time: new \DateTimeImmutable(),
            level: LogLevel::ERROR,
            message: 'Failure',
            context: ['exception' => $exception],
        );

        Assert::same($record->context['exception'] ?? null, [
            'type' => $exception::class,
            'message' => '',
            'file' => '',
            'line' => 0,
        ]);
    }

    public function dropsInvalidUtf8KeysBeforeEncodingJson(): void
    {
        $output = new RecordingWriter();
        $logger = new Logger($output);

        $logger->info('Context', ["\u{FFFD}" => 'valid key', "\xff" => 'invalid key']);

        Assert::string($output->writes[0] ?? '')->endsWith("\"context\":{\"\u{FFFD}\":\"valid key\"}}\n");
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
        $logger->warning($message, $context);

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

    public function capturesTheConfiguredClockTimeBeforeCastingTheMessage(): void
    {
        $output = new RecordingWriter();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-23T22:12:00.012345+03:00'));
        $logger = new Logger($output, clock: $clock);

        $message = new readonly class ($clock) implements \Stringable {
            public function __construct(
                private FrozenClock $clock,
            ) {}

            public function __toString(): string
            {
                // @phpstan-ignore constructor.call (A clock can return an object its owner reinitializes.)
                $this->clock->now->__construct('2026-11-23T14:13:00.012345-05:00');

                return 'First';
            }
        };
        $logger->info($message);
        $logger->info('Second');

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
        $logger = new Logger(new StreamHandler(new RecordingWriter(), $formatter));
        $long = str_repeat('a', 1100);
        $key = str_repeat('k', 128);

        $error = new \ErrorException($long, filename: $long, line: 42);
        $logger->info(str_repeat('a', 1020) . '{field}', [
            $key => 'Kept',
            'field' => 'Value',
        ] + array_fill(1, 31, true));
        $logger->info('{message}{message}', ['message' => $long, 'exception' => $error]);
        $logger->info('Summarized', ['exception' => 'Reason', 'other' => new \RuntimeException('Text')]);

        Assert::count($captured, 3);
        $first = $captured[0] ?? throw new \LogicException('The first record was not formatted.');
        $second = $captured[1] ?? throw new \LogicException('The second record was not formatted.');
        $third = $captured[2] ?? throw new \LogicException('The third record was not formatted.');
        $clipped = str_repeat('a', 1024);
        Assert::same($first->message, str_repeat('a', 1020) . '{fie');
        Assert::same($first->context, [$key => 'Kept', 'field' => 'Value'] + array_fill(1, 30, true));
        Assert::same($second->message, $clipped);
        $exception = ['type' => \ErrorException::class, 'message' => $clipped, 'file' => $clipped, 'line' => 42];
        Assert::same($second->context['exception'] ?? null, $exception);
        Assert::same($third->context, ['exception' => 'Reason', 'other' => '[RuntimeException]']);
    }

    public function writesFormatterOutputWithATrailingNewline(): void
    {
        $output = new RecordingWriter();
        $formatted = str_repeat('a', 16 * 1024) . "\r\nSecond line";
        $formatter = new CallbackFormatter(static fn(Record $record) => $formatted);
        $logger = new Logger(new StreamHandler($output, $formatter));

        $logger->info('Message');

        Assert::same($output->writes, [$formatted . "\n"]);
    }

    public function propagatesFormatterFailure(): void
    {
        $failure = new \RuntimeException('The formatter failed.');
        $output = new RecordingWriter();
        $formatter = new CallbackFormatter(static fn() => throw $failure);
        $logger = new Logger(new StreamHandler($output, $formatter));

        Assert::same(thrown(static fn() => $logger->info('Message')), $failure);
        Assert::same($output->writes, []);
    }

    public function propagatesWriteFailure(): void
    {
        $output = new class implements Writer {
            #[\Override]
            public function write(string $bytes): void
            {
                throw new WriteFailed('Output unavailable.');
            }
        };
        $direct = new Logger($output);
        Assert::instanceOf(thrown(static fn() => $direct->info('First')), WriteFailed::class);
    }
}

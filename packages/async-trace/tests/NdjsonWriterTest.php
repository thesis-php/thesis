<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Cancelled;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Trace\Event;
use Thesis\Async\Trace\Joining;
use Thesis\Dispatcher;
use Thesis\Time\FrozenClock;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\observe;
use function Thesis\AsyncTrace\Tests\unheardEvents;
use function Thesis\Dispatcher\listenersOf;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class NdjsonWriterTest
{
    public function writesHeaderAndLifecycleOfTask(): void
    {
        $line = __LINE__ + 1;
        $records = self::write(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(1));

                return 42;
            }, name: 'answer');
        }, name: 'main'));

        Assert::same($records, [
            ['format' => 'thesis/async-trace', 'version' => 1, 'start' => '2026-11-17T04:38:36.250000+00:00'],
            [
                'event' => 'GroupCreated',
                'scope' => 1,
                'at' => 0,
                'parent' => null,
                'function' => new \ReflectionFunction(group(...))->getName(),
                'name' => 'main',
                'cancelled' => false,
                'location' => ['file' => __FILE__, 'line' => $line],
            ],
            [
                'event' => 'TaskCreated',
                'scope' => 2,
                'at' => 0,
                'group' => 1,
                'daemon' => false,
                'name' => 'answer',
                'task' => \sprintf('function@%s:%d()', __FILE__, $line + 1),
                'cancelled' => false,
                'location' => ['file' => __FILE__, 'line' => $line + 1],
            ],
            ['event' => 'Joining', 'scope' => 1, 'at' => 0],
            ['event' => 'TaskStarted', 'scope' => 2, 'at' => 0],
            [
                'event' => 'Suspended',
                'scope' => 2,
                'at' => 0,
                'caller' => ['callee' => 'Thesis\Async\delay', 'file' => __FILE__, 'line' => $line + 2],
            ],
            ['event' => 'Resumed', 'scope' => 2, 'at' => 1_000_000_000, 'error' => null],
            ['event' => 'Ended', 'scope' => 2, 'at' => 1_000_000_000, 'value' => '42', 'error' => null],
            ['event' => 'Ended', 'scope' => 1, 'at' => 1_000_000_000, 'value' => 'null', 'error' => null],
        ]);
    }

    public function writesTimeoutCancelledByDeadline(): void
    {
        $line = __LINE__ + 1;
        $records = self::write(static fn() => timeoutOr(ms(1500), static fn() => delay(sec(5))));

        // Where the library throws Cancelled is its own business: only the class and the message are checked.
        Assert::same(self::withoutPlaceOfErrors(\array_slice($records, 1)), [
            [
                'event' => 'TimeoutCreated',
                'scope' => 1,
                'at' => 0,
                'parent' => null,
                'duration' => 1_500_000_000,
                'cancelled' => false,
                'location' => ['file' => __FILE__, 'line' => $line],
            ],
            [
                'event' => 'Suspended',
                'scope' => 1,
                'at' => 0,
                'caller' => ['callee' => 'Thesis\Async\delay', 'file' => __FILE__, 'line' => $line],
            ],
            [
                'event' => 'ScopeCancelled',
                'scope' => 1,
                'at' => 1_500_000_000,
                'cause' => 'Deadline',
                'by' => 1,
                'reached' => [1],
            ],
            [
                'event' => 'Resumed',
                'scope' => 1,
                'at' => 1_500_000_000,
                'error' => ['class' => Cancelled::class, 'message' => 'The scope is cancelled.'],
            ],
            [
                'event' => 'Ended',
                'scope' => 1,
                'at' => 1_500_000_000,
                'value' => null,
                'error' => ['class' => Cancelled::class, 'message' => 'The scope is cancelled.'],
            ],
        ]);
    }

    public function writesFailureCancellingGroup(): void
    {
        $line = __LINE__ + 1;
        $records = self::write(static fn() => self::thrown(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)));
            $group->spawn(static function () {
                delay(sec(1));

                throw new \RuntimeException('boom');
            });
        })));

        Assert::same(self::ofEvent($records, 'ScopeCancelled'), [[
            'event' => 'ScopeCancelled',
            'scope' => 1,
            'at' => 1_000_000_000,
            'cause' => 'Failure',
            'by' => 3,
            'reached' => [1, 2],
        ]]);
        Assert::same(self::ofEvent($records, 'Ended')[0] ?? null, [
            'event' => 'Ended',
            'scope' => 3,
            'at' => 1_000_000_000,
            'value' => null,
            'error' => [
                'class' => \RuntimeException::class,
                'message' => 'boom',
                'file' => __FILE__,
                'line' => $line + 5,
            ],
        ]);
    }

    public function writesShield(): void
    {
        $line = __LINE__ + 1;
        $records = self::write(static fn() => shield(static fn() => null));

        Assert::same(self::ofEvent($records, 'ShieldCreated'), [[
            'event' => 'ShieldCreated',
            'scope' => 1,
            'at' => 0,
            'parent' => null,
            'cancelled' => false,
            'location' => ['file' => __FILE__, 'line' => $line],
        ]]);
    }

    public function replacesInvalidUtf8(): void
    {
        $records = self::write(static fn() => group(static fn() => null, name: "bad \xFF"));

        Assert::same(array_column(self::ofEvent($records, 'GroupCreated'), 'name'), ["bad \u{FFFD}"]);
    }

    public function takesNoEventOfNewerAsyncItDoesNotKnow(): void
    {
        $dispatcher = new Dispatcher(listenersOf(new NdjsonWriter(self::memory('w+'), self::clock())));

        Assert::false($dispatcher->listens(Event::class));
    }

    public function listensToEveryEventOfAsync(): void
    {
        Assert::same(unheardEvents(new NdjsonWriter(self::memory('w+'), self::clock())), []);
    }

    public function countsLinesItCouldNotWriteWithoutThrowing(): void
    {
        $writer = new NdjsonWriter(self::memory('r'), self::clock());

        $writer->onJoining(new Joining(scope: 1, at: Instant::now()));

        Assert::same($writer->lost, 2);
    }

    /**
     * @param callable(): mixed $fn
     */
    private static function thrown(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }

    /**
     * @param callable(): mixed $program
     * @return list<array<string, mixed>>
     */
    private static function write(callable $program): array
    {
        $stream = self::memory('w+');
        observe(new Dispatcher(listenersOf(new NdjsonWriter($stream, self::clock()))), $program);

        return self::read($stream);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private static function ofEvent(array $records, string $event): array
    {
        return array_values(array_filter($records, static fn(array $record) => ($record['event'] ?? null) === $event));
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private static function withoutPlaceOfErrors(array $records): array
    {
        return array_map(static function (array $record) {
            if (\is_array($record['error'] ?? null)) {
                unset($record['error']['file'], $record['error']['line']);
            }

            return $record;
        }, $records);
    }

    /**
     * @param 'r'|'w+' $mode
     * @return resource
     */
    private static function memory(string $mode): mixed
    {
        $stream = fopen('php://memory', $mode);

        if ($stream === false) {
            throw new \LogicException('Cannot open php://memory.');
        }

        return $stream;
    }

    private static function clock(): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable('2026-11-17T04:38:36.25+00:00'));
    }

    /**
     * @param resource $stream
     * @return list<array<string, mixed>>
     */
    private static function read(mixed $stream): array
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        Assert::string($contents);
        Assert::true(str_ends_with($contents, "\n"));

        /** @var non-empty-list<array<string, mixed>> */
        return array_map(
            static fn(string $line) => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            explode("\n", substr($contents, 0, -1)),
        );
    }
}

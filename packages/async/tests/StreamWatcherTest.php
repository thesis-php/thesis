<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\elapsed;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;

#[Test]
final class StreamWatcherTest
{
    public function readableWaitsForBytes(): void
    {
        [$reader, $writer] = self::pair();
        $watcher = new StreamWatcher($reader);

        $elapsed = group(static function (Group $group) use ($watcher, $writer) {
            $group->spawn(static function () use ($writer) {
                delay(10);
                fwrite($writer, 'ping');
            });

            return elapsed(static fn() => Assert::true($watcher->readable()));
        });

        Assert::same($elapsed, 10.0);
        Assert::same(fread($reader, 4), 'ping');
        assertNoCallbacksLeft();
    }

    public function writableReturnsOnceBufferHasRoom(): void
    {
        [, $writer] = self::pair();

        Assert::true(new StreamWatcher($writer)->writable());
        assertNoCallbacksLeft();
    }

    public function waitIsCancelledByTaskTree(): void
    {
        $pair = self::pair();
        $watcher = new StreamWatcher($pair[0]);
        $exception = null;

        $elapsed = elapsed(static function () use ($watcher, &$exception) {
            $exception = thrown(static fn() => timeout(1, $watcher->readable(...)));
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($elapsed, 1.0);
        assertNoCallbacksLeft();
    }

    public function closeEndsEveryWaitWithFalse(): void
    {
        $pair = self::stalled();
        $watcher = new StreamWatcher($pair[0]);

        $results = group(static function (Group $group) use ($watcher, $pair) {
            $handles = [
                $group->spawn($watcher->readable(...)),
                $group->spawn($watcher->writable(...)),
                $group->spawn(static fn() => $watcher->delay(100)),
            ];

            delay(1);
            $watcher->close();
            fclose($pair[0]);

            return array_map(static fn(Task $handle) => $handle->await(), $handles);
        });

        Assert::same($results, [false, false, false]);
        Assert::true($watcher->isClosed);
        assertNoCallbacksLeft();
    }

    public function closeTakesEffectAtTheTimeOfTheCall(): void
    {
        $pair = self::pair();
        $watcher = new StreamWatcher($pair[0]);

        $elapsed = group(static function (Group $group) use ($watcher) {
            $group->spawn(static function () use ($watcher) {
                delay(3);
                $watcher->close();
            });

            return elapsed(static fn() => Assert::false($watcher->readable()));
        });

        Assert::same($elapsed, 3.0);
        assertNoCallbacksLeft();
    }

    public function waitAfterCloseReturnsFalseAtOnce(): void
    {
        [$reader] = self::pair();
        $watcher = new StreamWatcher($reader);
        $watcher->close();
        fclose($reader);

        $elapsed = elapsed(static function () use ($watcher) {
            Assert::false($watcher->readable());
            Assert::false($watcher->writable());
            Assert::false($watcher->delay(10));
        });

        Assert::same($elapsed, 0.0);
        assertNoCallbacksLeft();
    }

    public function waitAfterCloseIsNotCancellationPoint(): void
    {
        [$reader] = self::pair();
        $watcher = new StreamWatcher($reader);
        $watcher->close();

        $readable = timeoutOr(1, static function () use ($watcher) {
            untilCancelled();

            return $watcher->readable();
        }, default: 'expired');

        Assert::same($readable, false);
    }

    public function closeIsIdempotent(): void
    {
        [$reader] = self::pair();
        $watcher = new StreamWatcher($reader);

        $watcher->close();
        $watcher->close();

        Assert::true($watcher->isClosed);
    }

    public function isNotClosedUntilClose(): void
    {
        [$reader] = self::pair();

        Assert::false(new StreamWatcher($reader)->isClosed);
    }

    public function delayWaitsGivenTime(): void
    {
        [$reader] = self::pair();
        $watcher = new StreamWatcher($reader);
        $delayed = null;

        $elapsed = elapsed(static function () use ($watcher, &$delayed) {
            $delayed = $watcher->delay(20);
        });

        Assert::true($delayed);
        Assert::same($elapsed, 20.0);
        assertNoCallbacksLeft();
    }

    public function delayOfZeroOrLessWaitsAsZero(): void
    {
        [$reader] = self::pair();
        $watcher = new StreamWatcher($reader);

        Assert::same(elapsed(static fn() => Assert::true($watcher->delay(-1))), 0.0);
    }

    public function delayRejectsNan(): void
    {
        [$reader] = self::pair();

        Expect::exception(\ValueError::class);

        new StreamWatcher($reader)->delay(NAN);
    }

    public function delayIsCancelledByTaskTree(): void
    {
        [$reader] = self::pair();
        $watcher = new StreamWatcher($reader);

        Expect::exception(TimedOut::class);

        timeout(1, static fn() => $watcher->delay(100));
    }

    public function readableReturnsForStreamWithBytes(): void
    {
        [$reader, $writer] = self::pair();
        fwrite($writer, 'ping');

        Assert::true(new StreamWatcher($reader)->readable());
        assertNoCallbacksLeft();
    }

    public function waitInCancelledScopeThrowsWithoutWatcher(): void
    {
        [$reader, $writer] = self::pair();
        fwrite($writer, 'ping');
        $watcher = new StreamWatcher($reader);

        $exception = thrown(static fn() => timeout(1, static function () use ($watcher) {
            untilCancelled();

            $watcher->readable();
        }));

        Assert::instanceOf($exception, TimedOut::class);
        assertNoCallbacksLeft();
    }

    /**
     * Two connected sockets. Keep both: once one is freed, the other one reads the end of the stream.
     *
     * @return array{resource, resource}
     */
    private static function pair(): array
    {
        /** @var array{resource, resource} */
        return stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
    }

    /**
     * {@see pair()} whose first socket is neither readable nor writable: nobody reads what it has written.
     *
     * @return array{resource, resource}
     */
    private static function stalled(): array
    {
        $pair = self::pair();
        stream_set_blocking($pair[0], false);

        while (fwrite($pair[0], str_repeat('x', 65_536)) > 0);

        return $pair;
    }
}

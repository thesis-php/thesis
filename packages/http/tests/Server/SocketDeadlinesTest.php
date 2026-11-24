<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Http\Internal\Server\SocketDeadlines;
use Thesis\Io\WriteFailed;
use Thesis\Net\SocketClosed;
use Thesis\TestoPlugin\VirtualTime;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;
use function Thesis\Net\socketPair;
use function Thesis\Time\sec;

#[Test]
#[Covers(SocketDeadlines::class)]
final class SocketDeadlinesTest
{
    #[VirtualTime]
    public function readingAgainMovesTheSocketBehindOlderWaits(): void
    {
        group(static function (Group $group) {
            $idle = new SocketDeadlines($group, sec(3));
            [$first, $peer] = socketPair();
            [$second, $other] = socketPair();
            $start = Instant::now();
            $read = $group->spawn(static fn() => $idle->read($first, 1));
            delay(sec(1));
            $older = $group->spawn(static fn() => $idle->read($second, 1));
            delay(sec(1));
            $peer->write('a');
            Assert::same('a', $read->await());
            $newer = $group->spawn(static fn() => $idle->read($first, 1));

            Assert::null($older->await());
            Assert::equals(sec(4), Instant::now()->since($start));
            Assert::false($newer->isDone);
            Assert::null($newer->await());
            Assert::equals(sec(5), Instant::now()->since($start));
            Assert::true($first->isClosed);
            Assert::true($second->isClosed);
            $peer->close();
            $other->close();
        });
    }

    #[VirtualTime]
    public function aFinishedReadCanStayOutOfTheQueuePastItsOldDeadline(): void
    {
        group(static function (Group $group) {
            $idle = new SocketDeadlines($group, sec(3));
            [$socket, $peer] = socketPair();
            $read = $group->spawn(static fn() => $idle->read($socket, 1));
            delay(sec(1));
            $peer->write('a');
            Assert::same('a', $read->await());
            delay(sec(4));
            Assert::false($socket->isClosed);

            $start = Instant::now();
            Assert::null($idle->read($socket, 1));
            Assert::equals(sec(3), Instant::now()->since($start));
            $peer->close();
        });
    }

    #[VirtualTime]
    public function waitingForAnotherWriterCountsAgainstTheDeadline(): void
    {
        group(static function (Group $group) {
            $writes = new SocketDeadlines($group, sec(3));
            [$socket, $peer] = socketPair();
            $owner = $group->spawn(static function () use ($socket) {
                try {
                    $socket->write(str_repeat('x', 8 * 1024 * 1024));
                } catch (SocketClosed) {
                    return true;
                }

                return false;
            });
            delay(sec(1));
            $start = Instant::now();

            $queued = $group->spawn(static function () use ($socket, $writes) {
                try {
                    $writes->write($socket, 'b');
                } catch (WriteFailed $error) {
                    return $error->getMessage();
                }

                return null;
            });
            Assert::same('The response write did not complete in time.', $queued->await());

            Assert::equals(sec(3), Instant::now()->since($start));
            Assert::true($owner->await());
            $peer->close();
        });
    }

    #[VirtualTime]
    public function cancellingAfterAnImmediatePartialWriteClosesTheSocket(): void
    {
        group(static function (Group $group) {
            $writes = new SocketDeadlines($group, sec(3));
            [$socket, $peer] = socketPair();
            timeoutOr(sec(1), static fn() => $writes->write($socket, str_repeat('x', 8 * 1024 * 1024)));

            Assert::true($socket->isClosed);
            Assert::same($peer->read(1), 'x');
        });
    }

    #[VirtualTime]
    public function aQueuedWriteDoesNotExtendTheFirstDeadline(): void
    {
        group(static function (Group $group) {
            $writes = new SocketDeadlines($group, sec(3));
            [$slow, $peer] = socketPair();
            [$fast, $receiver] = socketPair();
            $start = Instant::now();
            $first = $group->spawn(static function () use ($writes, $slow) {
                try {
                    $writes->write($slow, str_repeat('x', 8 * 1024 * 1024));
                } catch (WriteFailed $error) {
                    return $error->getMessage();
                }

                return null;
            });
            delay(sec(1));
            $second = $group->spawn(static function () use ($writes, $slow) {
                try {
                    $writes->write($slow, 'b');
                } catch (SocketClosed) {
                    return true;
                }

                return false;
            });
            $writes->write($fast, 'ok');
            Assert::same('ok', $receiver->read(2));
            Assert::same('The response write did not complete in time.', $first->await());
            Assert::equals(sec(3), Instant::now()->since($start));
            Assert::true($second->await());
            $fast->close();
            $receiver->close();
            $peer->close();
        });
    }
}

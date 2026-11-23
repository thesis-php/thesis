<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\HttpServer\Internal\IdleConnections;
use Thesis\TestoPlugin\VirtualTime;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Net\socketPair;
use function Thesis\Time\sec;

#[Test]
#[Covers(IdleConnections::class)]
final class IdleConnectionsTest
{
    #[VirtualTime]
    public function readingAgainMovesTheSocketBehindOlderWaits(): void
    {
        group(static function (Group $group) {
            $idle = new IdleConnections($group, sec(3));
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
            $idle = new IdleConnections($group, sec(3));
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
}

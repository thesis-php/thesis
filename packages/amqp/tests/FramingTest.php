<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Testo\Assert;
use Testo\Test;
use Thesis\Amqp\Internal\Protocol\BasicDeliver;
use Thesis\Amqp\Internal\Protocol\BasicGet;
use Thesis\Amqp\Internal\Protocol\BasicGetOk;
use Thesis\Amqp\Internal\Protocol\ConnectionClose;
use Thesis\Amqp\Internal\Protocol\ConnectionCloseOk;
use Thesis\Async\Group;
use function Thesis\Async\group;

/**
 * What the reader makes of the frames of the server.
 */
#[Test]
final class FramingTest
{
    public function assemblesABodyOfSeveralFramesWithHeartbeatsBetween(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $peer->expect(BasicGet::class);
                $peer->sendInPieces($id, new BasicGetOk(1, false, '', 'q', 0), ['first ', 'second ', 'third']);
            });

            Assert::same($channel->get('q')?->message->body, 'first second third');
        });
    }

    public function closesTheConnectionOnAFrameOverTheMaximum(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $peer->sendInPieces($id, new BasicDeliver('ctag', 1, false, '', 'q'), [str_repeat('x', 5000)]);

            self::assertClosedWith($peer, ReplyCode::FrameError);
            Assert::true($channel->isClosed);
        });
    }

    public function closesTheConnectionOnAMethodInTheMiddleOfAContent(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $peer->sendInPieces($id, new BasicDeliver('ctag', 1, false, '', 'q'), ['half'], bodySize: 8);
            $peer->send($id, new BasicDeliver('ctag', 2, false, '', 'q'));

            self::assertClosedWith($peer, ReplyCode::UnexpectedFrame);
            Assert::true($channel->isClosed);
        });
    }

    /**
     * The client tells the server why, as the protocol asks, and closes.
     */
    private static function assertClosedWith(FakePeer $peer, ReplyCode $replyCode): void
    {
        $close = $peer->expect(ConnectionClose::class);
        Assert::same($close->replyCode, $replyCode->value);
        $peer->send(0, new ConnectionCloseOk());
    }

    /**
     * A connection with a frame maximum of 4096 bytes and a channel on it.
     *
     * @param callable(Group, Channel, FakePeer, int<1, 65535>): void $test
     */
    private static function withChannel(callable $test): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server, $test) {
            $peer = $group->spawn(static function () use ($server) {
                $peer = $server->accept();
                $peer->handshake(frameMax: 4096);

                return $peer;
            });
            $client = new Client($server->config());
            $client->run($group);
            $peer = $peer->await();
            [$channel, $id] = openChannel($group, $client, $peer);

            try {
                $test($group, $channel, $peer, $id);
            } finally {
                $peer->socket->close();
            }
        });
    }
}

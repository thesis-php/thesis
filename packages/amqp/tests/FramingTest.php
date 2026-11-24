<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Testo\Assert;
use Testo\Test;
use Thesis\Amqp\Internal\Frames;
use Thesis\Amqp\Internal\Protocol\BasicDeliver;
use Thesis\Amqp\Internal\Protocol\BasicGet;
use Thesis\Amqp\Internal\Protocol\BasicGetOk;
use Thesis\Amqp\Internal\Protocol\BasicPublish;
use Thesis\Amqp\Internal\Protocol\ConnectionClose;
use Thesis\Amqp\Internal\Protocol\ConnectionCloseOk;
use Thesis\Async\Group;
use Thesis\Io\Buffer;
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

    public function closesTheConnectionOnABodySizeOverPhpIntMax(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $peer->send($id, new BasicDeliver('ctag', 1, false, '', 'q'));
            $header = new Buffer()
                ->writeUint16(BasicPublish::CLASS_ID)
                ->writeUint16(0)
                ->write(str_repeat("\xFF", 8))
                ->writeUint16(0) // no properties
            ;
            self::sendFrame($peer, Frames::HEADER, $id, $header->drain());

            self::assertClosedWith($peer, ReplyCode::FrameError);
            Assert::true($channel->isClosed);
        });
    }

    public function closesTheConnectionOnADeliveryTagOverPhpIntMax(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $deliver = new Buffer()
                ->writeUint16(BasicDeliver::CLASS_ID)
                ->writeUint16(BasicDeliver::METHOD_ID)
                ->writeUint8(4)
                ->write('ctag')
                ->write(str_repeat("\xFF", 8))
                ->writeUint8(0) // not redelivered
                ->writeUint8(0) // the exchange
                ->writeUint8(1)
                ->write('q')
            ;
            self::sendFrame($peer, Frames::METHOD, $id, $deliver->drain());

            self::assertClosedWith($peer, ReplyCode::FrameError);
            Assert::true($channel->isClosed);
        });
    }

    /**
     * A frame with a payload that the encoders of the client would never produce.
     *
     * @param int<0, 65535> $channel
     */
    private static function sendFrame(FakePeer $peer, int $type, int $channel, string $payload): void
    {
        $frame = new Buffer()
            ->writeUint8($type)
            ->writeUint16($channel)
            ->writeUint32(\strlen($payload))
            ->write($payload)
            ->writeUint8(Frames::END)
        ;
        $peer->socket->write($frame->drain());
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
            $client->start($group);
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

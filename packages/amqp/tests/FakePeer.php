<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Testo\Assert;
use Thesis\Amqp\Internal\ContentHeader;
use Thesis\Amqp\Internal\Frames;
use Thesis\Amqp\Internal\Protocol\BasicProperties;
use Thesis\Amqp\Internal\Protocol\BasicPublish;
use Thesis\Amqp\Internal\Protocol\ChannelOpen;
use Thesis\Amqp\Internal\Protocol\ChannelOpenOk;
use Thesis\Amqp\Internal\Protocol\ConnectionClose;
use Thesis\Amqp\Internal\Protocol\ConnectionCloseOk;
use Thesis\Amqp\Internal\Protocol\ConnectionOpen;
use Thesis\Amqp\Internal\Protocol\ConnectionOpenOk;
use Thesis\Amqp\Internal\Protocol\ConnectionStart;
use Thesis\Amqp\Internal\Protocol\ConnectionStartOk;
use Thesis\Amqp\Internal\Protocol\ConnectionTune;
use Thesis\Amqp\Internal\Protocol\ConnectionTuneOk;
use Thesis\Amqp\Internal\Protocol\Method;
use Thesis\Amqp\Internal\Protocol\Methods;
use Thesis\Io\Buffer;
use Thesis\Net\Socket;
use function Thesis\Amqp\Internal\message;

/**
 * The broker side of one connection: reads the frames of the client and sends those a test scripts.
 */
final readonly class FakePeer
{
    private Buffer $in;

    public function __construct(
        public Socket $socket,
    ) {
        $this->in = new Buffer();
    }

    /**
     * @param int<0, 65535> $channelMax
     * @param int<0, 4294967295> $frameMax
     * @param int<0, 65535> $heartbeat
     */
    public function handshake(
        string $mechanisms = 'PLAIN AMQPLAIN',
        int $channelMax = 2047,
        int $frameMax = 131_072,
        int $heartbeat = 0,
    ): ConnectionStartOk {
        $startOk = $this->start($mechanisms);
        $this->tune($channelMax, $frameMax, $heartbeat);

        return $startOk;
    }

    /**
     * The first half of the handshake.
     */
    public function start(string $mechanisms = 'PLAIN AMQPLAIN'): ConnectionStartOk
    {
        $this->in->fill($this->socket, 8);
        Assert::same($this->in->read(8), Frames::PROTOCOL_HEADER);
        $this->send(0, new ConnectionStart(0, 9, ['product' => 'FakeServer'], $mechanisms, 'en_US'));

        return $this->expect(ConnectionStartOk::class);
    }

    /**
     * The second half of the handshake: what the client has chosen.
     *
     * @param int<0, 65535> $channelMax
     * @param int<0, 4294967295> $frameMax
     * @param int<0, 65535> $heartbeat
     */
    public function tune(int $channelMax = 2047, int $frameMax = 131_072, int $heartbeat = 0): ConnectionTuneOk
    {
        $this->send(0, new ConnectionTune($channelMax, $frameMax, $heartbeat));
        $tuneOk = $this->expect(ConnectionTuneOk::class);
        $this->expect(ConnectionOpen::class);
        $this->send(0, new ConnectionOpenOk());

        return $tuneOk;
    }

    /**
     * Answers the next channel.open.
     *
     * @return int<1, 65535> the channel
     */
    public function openChannel(): int
    {
        [$channel] = $this->expectOn(ChannelOpen::class);
        \assert($channel > 0);
        $this->send($channel, new ChannelOpenOk());

        return $channel;
    }

    /**
     * @template T of Method
     * @param class-string<T> $class
     * @return T
     */
    public function expect(string $class): Method
    {
        return $this->expectOn($class)[1];
    }

    /**
     * @template T of Method
     * @param class-string<T> $class
     * @return array{int<0, 65535>, T}
     */
    public function expectOn(string $class): array
    {
        [$channel, $frame] = $this->frame();
        Assert::instanceOf($frame, $class);

        return [$channel, $frame];
    }

    /**
     * The content that follows a basic.publish.
     */
    public function expectMessage(): Message
    {
        [, $header] = $this->frame();
        Assert::instanceOf($header, ContentHeader::class);
        $body = '';

        while (\strlen($body) < $header->bodySize) {
            [, $frame] = $this->frame();
            Assert::string($frame);
            $body .= $frame;
        }

        return message($body, $header->properties);
    }

    /**
     * The next frame that is not a heartbeat.
     *
     * @return array{int<0, 65535>, Method|ContentHeader|string}
     */
    public function frame(): array
    {
        while (true) {
            $this->in->fill($this->socket, 7);
            $type = $this->in->readUint8();
            $channel = $this->in->readUint16();
            $size = $this->in->readUint32();
            $this->in->fill($this->socket, $size + 1);
            $payload = $this->in->slice($size);
            Assert::same($this->in->readUint8(), Frames::END);

            $frame = match ($type) {
                Frames::METHOD => Methods::decode($payload),
                Frames::HEADER => ContentHeader::decode($payload),
                Frames::BODY => $payload->drain(),
                default => null,
            };

            if ($frame !== null) {
                return [$channel, $frame];
            }
        }
    }

    /**
     * @param int<0, 65535> $channel
     */
    public function send(int $channel, Method $method): void
    {
        $buffer = new Buffer();
        Frames::method($buffer, $channel, $method);
        $this->socket->write($buffer->drain());
    }

    /**
     * A method with content: basic.deliver, basic.get-ok or basic.return.
     *
     * @param int<0, 65535> $channel
     */
    public function sendContent(int $channel, Method $method, string $body, ?BasicProperties $properties = null): void
    {
        $buffer = new Buffer();
        Frames::method($buffer, $channel, $method);
        Frames::content($buffer, $channel, $properties ?? new BasicProperties(), $body, 4096);
        $this->socket->write($buffer->drain());
    }

    /**
     * A method with content whose body frames are $pieces, each followed by a heartbeat. The header announces
     * $bodySize, the length of the pieces if null: a test may announce more than it sends.
     *
     * @param int<0, 65535> $channel
     * @param list<string> $pieces
     * @param ?non-negative-int $bodySize
     */
    public function sendInPieces(int $channel, Method $method, array $pieces, ?int $bodySize = null): void
    {
        $buffer = new Buffer();
        Frames::method($buffer, $channel, $method);
        $buffer->writeUint8(Frames::HEADER)->writeUint16($channel);
        $mark = $buffer->reserve(4);
        $buffer
            ->writeUint16(BasicPublish::CLASS_ID)
            ->writeUint16(0)
            ->writeUint64($bodySize ?? \strlen(implode('', $pieces)))
        ;
        new BasicProperties()->encode($buffer);
        $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);
        $buffer->writeUint8(Frames::END);

        foreach ($pieces as $piece) {
            $buffer
                ->writeUint8(Frames::BODY)
                ->writeUint16($channel)
                ->writeUint32(\strlen($piece))
                ->write($piece)
                ->writeUint8(Frames::END)
            ;
            Frames::heartbeat($buffer);
        }

        $this->socket->write($buffer->drain());
    }

    public function heartbeat(): void
    {
        $buffer = new Buffer();
        Frames::heartbeat($buffer);
        $this->socket->write($buffer->drain());
    }

    /**
     * Answers the connection.close of the client.
     */
    public function acceptClose(): void
    {
        $this->expect(ConnectionClose::class);
        $this->send(0, new ConnectionCloseOk());
        $this->socket->close();
    }
}

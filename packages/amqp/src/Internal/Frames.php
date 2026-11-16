<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\Internal\Protocol\BasicProperties;
use Thesis\Amqp\Internal\Protocol\Method;
use Thesis\Amqp\Internal\Protocol\ProtocolError;
use Thesis\Amqp\ReplyCode;
use Thesis\Io\Buffer;
use Thesis\Io\IoException;
use Thesis\Io\UnexpectedEof;
use Thesis\Net\Socket;
use function Thesis\Amqp\Internal\Protocol\decodeMethod;

/**
 * Encodes and reads frames: a frame is a type, a channel, a size, the payload and the end octet.
 *
 * @internal
 */
final class Frames
{
    public const int METHOD = 1;
    public const int HEADER = 2;
    public const int BODY = 3;
    public const int HEARTBEAT = 8;
    public const int END = 0xCE;
    public const string PROTOCOL_HEADER = "AMQP\x00\x00\x09\x01";

    /**
     * The type, the channel and the size before the payload, the end octet after it.
     */
    public const int OVERHEAD = 8;

    /**
     * The frame size of RabbitMQ: a body is split into frames of it when the server sets no limit.
     */
    public const int DEFAULT_MAX = 131_072;

    /**
     * @param int<0, 65535> $channel
     * @throws \ValueError if a field cannot be encoded
     */
    public static function method(Buffer $buffer, int $channel, Method $method): void
    {
        $buffer->writeUint8(self::METHOD)->writeUint16($channel);
        $mark = $buffer->reserve(4);
        $method->encode($buffer);
        $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);
        $buffer->writeUint8(self::END);
    }

    /**
     * A content header frame and as many body frames as the body needs.
     *
     * @param int<0, 65535> $channel
     * @param positive-int $frameMax
     * @throws \ValueError if a property cannot be encoded
     */
    public static function content(
        Buffer $buffer,
        int $channel,
        BasicProperties $properties,
        string $body,
        int $frameMax,
    ): void {
        $buffer->writeUint8(self::HEADER)->writeUint16($channel);
        $mark = $buffer->reserve(4);
        $buffer->writeUint16(Protocol\BasicPublish::CLASS_ID)->writeUint16(0)->writeUint64(\strlen($body));
        $properties->encode($buffer);
        $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);
        $buffer->writeUint8(self::END);

        $chunkSize = $frameMax - self::OVERHEAD;
        $length = \strlen($body);

        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            $chunk = substr($body, $offset, $chunkSize);
            $buffer
                ->writeUint8(self::BODY)
                ->writeUint16($channel)
                ->writeUint32(\strlen($chunk))
                ->write($chunk)
                ->writeUint8(self::END)
            ;
        }
    }

    public static function heartbeat(Buffer $buffer): void
    {
        $buffer->writeUint8(self::HEARTBEAT)->writeUint16(0)->writeUint32(0)->writeUint8(self::END);
    }

    /**
     * @return array{int<0, 65535>, Method|ContentHeader|string|null} the channel and the frame:
     *     a method, a content header, a piece of a body, or null for a heartbeat
     * @throws IoException
     * @throws ProtocolError
     */
    public static function read(Socket $socket, Buffer $in, int $frameMax): array
    {
        $in->fill($socket, 7);
        $type = $in->readUint8();
        $channel = $in->readUint16();
        $size = $in->readUint32();

        if ($type === \ord('A')) {
            throw new ProtocolError(ReplyCode::FrameError, 'The server does not support AMQP 0-9-1.');
        }

        if ($frameMax > 0 && $size + self::OVERHEAD > $frameMax) {
            throw new ProtocolError(
                replyCode: ReplyCode::FrameError,
                message: "A frame of {$size} bytes exceeds the maximum of {$frameMax}.",
            );
        }

        $in->fill($socket, $size + 1);
        $payload = $in->slice($size);

        try {
            $frame = match ($type) {
                self::METHOD => decodeMethod($payload),
                self::HEADER => ContentHeader::decode($payload),
                self::BODY => $payload->drain(),
                self::HEARTBEAT => null,
                default => throw new ProtocolError(ReplyCode::FrameError, "Unknown frame type {$type}."),
            };
        } catch (UnexpectedEof) {
            throw new ProtocolError(ReplyCode::FrameError, "A frame of type {$type} is shorter than its fields.");
        }

        if ($payload->count() !== 0) {
            throw new ProtocolError(ReplyCode::FrameError, "A frame of type {$type} is longer than its fields.");
        }

        if ($in->readUint8() !== self::END) {
            throw new ProtocolError(ReplyCode::FrameError, 'A frame does not end with the frame end octet.');
        }

        return [$channel, $frame];
    }

    public static function describe(null|Method|ContentHeader|string $frame): string
    {
        return match (true) {
            $frame === null => 'a heartbeat',
            \is_string($frame) => 'a content body',
            $frame instanceof ContentHeader => 'a content header',
            default => $frame::class,
        };
    }
}

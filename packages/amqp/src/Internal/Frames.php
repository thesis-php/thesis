<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\Internal\Protocol\BasicProperties;
use Thesis\Amqp\Internal\Protocol\Method;
use Thesis\Io\Buffer;

/**
 * Encodes frames into a buffer: a frame is a type, a channel, a size, the payload and the end octet.
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
}

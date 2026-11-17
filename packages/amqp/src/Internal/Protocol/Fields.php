<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal\Protocol;

use Thesis\Amqp\ReplyCode;
use Thesis\Io\Buffer;

/**
 * The strings and the field tables of the protocol: the types of the fields that Buffer does not code itself.
 *
 * @internal
 */
final class Fields
{
    public static function readShortString(Buffer $buffer): string
    {
        return $buffer->read($buffer->readUint8());
    }

    /**
     * A runtime check, not an assert() as in Buffer: a user's routing key longer than 255 bytes would corrupt
     * the stream of the whole connection, not only fail its own call.
     *
     * @throws \ValueError if $value is longer than 255 bytes
     */
    public static function writeShortString(Buffer $buffer, string $value): void
    {
        $length = \strlen($value);

        if ($length > 255) {
            throw new \ValueError("A short string of AMQP is at most 255 bytes, got {$length}: \"{$value}\".");
        }

        $buffer->writeUint8($length)->write($value);
    }

    public static function readLongString(Buffer $buffer): string
    {
        return $buffer->read($buffer->readUint32());
    }

    public static function writeLongString(Buffer $buffer, string $value): void
    {
        $buffer->writeUint32(\strlen($value))->write($value);
    }

    /**
     * Integer keys are numeric string keys that PHP has converted: they are written as strings.
     *
     * @return array<string, mixed>
     * @throws ProtocolError
     */
    public static function readTable(Buffer $buffer): array
    {
        $length = $buffer->readUint32();
        $end = $buffer->count() - $length;
        $table = [];

        while ($buffer->count() > $end) {
            $key = self::readShortString($buffer);
            $table[$key] = self::readFieldValue($buffer);
        }

        /** @var array<string, mixed> */
        return $table;
    }

    /**
     * @param array<array-key, mixed> $table
     * @throws \ValueError if a value cannot be encoded
     */
    public static function writeTable(Buffer $buffer, array $table): void
    {
        $mark = $buffer->reserve(4);

        foreach ($table as $key => $value) {
            self::writeShortString($buffer, (string) $key);
            self::writeFieldValue($buffer, $value);
        }

        $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);
    }

    /**
     * The types of RabbitMQ, which differ from the specification: 's' is a signed 16-bit integer,
     * 'u' and 'U' are absent.
     *
     * @throws ProtocolError
     * @see https://www.rabbitmq.com/amqp-0-9-1-errata#section_3
     */
    private static function readFieldValue(Buffer $buffer): mixed
    {
        $type = $buffer->read(1);

        return match ($type) {
            't' => $buffer->readUint8() !== 0,
            'b' => $buffer->readInt8(),
            'B' => $buffer->readUint8(),
            's' => $buffer->readInt16(),
            'u' => $buffer->readUint16(),
            'I' => $buffer->readInt32(),
            'i' => $buffer->readUint32(),
            'l' => $buffer->readInt64(),
            'f' => $buffer->readFloat(),
            'd' => $buffer->readDouble(),
            'D' => self::readDecimal($buffer),
            'S', 'x' => self::readLongString($buffer),
            'A' => self::readArray($buffer),
            'T' => new \DateTimeImmutable('@' . $buffer->readUint64()),
            'F' => self::readTable($buffer),
            'V' => null,
            default => throw new ProtocolError(ReplyCode::SyntaxError, "Unknown field type \"{$type}\"."),
        };
    }

    /**
     * An int is a signed 32-bit integer if it fits, otherwise a signed 64-bit one: RabbitMQ reads either for
     * the numeric arguments (x-max-length, x-message-ttl). An empty array is a table: arguments and headers are.
     *
     * @throws \ValueError if the value cannot be encoded
     */
    private static function writeFieldValue(Buffer $buffer, mixed $value): void
    {
        match (true) {
            \is_bool($value) => $buffer->write('t')->writeUint8($value ? 1 : 0),
            \is_int($value) => self::writeInt($buffer, $value),
            \is_float($value) => $buffer->write('d')->writeDouble($value),
            \is_string($value) => self::writeLongString($buffer->write('S'), $value),
            $value === null => $buffer->write('V'),
            $value instanceof \DateTimeInterface => $buffer->write('T')->writeUint64(max(0, $value->getTimestamp())),
            \is_array($value) && $value !== [] && array_is_list($value) => self::writeArray(
                buffer: $buffer->write('A'),
                values: $value,
            ),
            \is_array($value) => self::writeTable($buffer->write('F'), $value),
            default => throw new \ValueError(
                \sprintf('A field table cannot hold a value of type %s.', get_debug_type($value)),
            ),
        };
    }

    private static function writeInt(Buffer $buffer, int $value): void
    {
        if ($value >= -2_147_483_648 && $value <= 2_147_483_647) {
            $buffer->write('I')->writeInt32($value);
        } else {
            $buffer->write('l')->writeInt64($value);
        }
    }

    /**
     * @return list<mixed>
     * @throws ProtocolError
     */
    private static function readArray(Buffer $buffer): array
    {
        $length = $buffer->readUint32();
        $end = $buffer->count() - $length;
        $values = [];

        while ($buffer->count() > $end) {
            $values[] = self::readFieldValue($buffer);
        }

        return $values;
    }

    /**
     * @param list<mixed> $values
     * @throws \ValueError
     */
    private static function writeArray(Buffer $buffer, array $values): void
    {
        $mark = $buffer->reserve(4);

        foreach ($values as $value) {
            self::writeFieldValue($buffer, $value);
        }

        $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);
    }

    /**
     * A decimal is read as a numeric string ("12.34"): PHP has no decimal type, and a float would round it.
     *
     * @return numeric-string
     */
    private static function readDecimal(Buffer $buffer): string
    {
        $scale = $buffer->readUint8();
        $value = $buffer->readInt32();

        if ($scale === 0) {
            return (string) $value;
        }

        $digits = str_pad((string) abs($value), $scale + 1, '0', STR_PAD_LEFT);
        $decimal = ($value < 0 ? '-' : '') . substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        \assert(is_numeric($decimal));

        return $decimal;
    }
}

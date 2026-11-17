<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal\Protocol;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Io\Buffer;

#[Test]
final class FieldsTest
{
    public function readsWhatItWrites(): void
    {
        $table = [
            'true' => true,
            'false' => false,
            'int32' => -2_147_483_648,
            'int64' => 2_147_483_648,
            'double' => 1.5,
            'string' => "with\0zero",
            'null' => null,
            'timestamp' => new \DateTimeImmutable('@1700000000'),
            'array' => [1, 'two', [3]],
            'table' => ['nested' => ['deeper' => 'yes']],
        ];

        Assert::equals(self::roundTrip($table), $table);
    }

    public function readsAShortLastEntry(): void
    {
        $table = ['long' => 'value', 'null' => null, 'bools' => [true, true, true], 'x' => true];

        Assert::same(self::roundTrip($table), $table);
    }

    public function writesAnEmptyArrayAsATable(): void
    {
        $buffer = new Buffer();
        Fields::writeTable($buffer, ['empty' => []]);

        Assert::same($buffer->drain(), "\0\0\0\x0B\x05emptyF\0\0\0\0");
    }

    public function writesIntegerKeysAsStrings(): void
    {
        $buffer = new Buffer();
        Fields::writeTable($buffer, [7 => 'seven']);

        Assert::same($buffer->drain(), "\0\0\0\x0C\x017S\0\0\0\x05seven");
    }

    public function readsTheTypesOfOtherClients(): void
    {
        $buffer = new Buffer();
        $mark = $buffer->reserve(4);
        $buffer->write("\x04int8b")->writeInt8(-1);
        $buffer->write("\x05uint8B")->writeUint8(255);
        $buffer->write("\x05int16s")->writeInt16(-2);
        $buffer->write("\x06uint16u")->writeUint16(65535);
        $buffer->write("\x06uint32i")->writeUint32(4_294_967_295);
        $buffer->write("\x05floatf")->writeFloat(0.5);
        $buffer->write("\x08decimal2D")->writeUint8(2)->writeInt32(-12_345);
        $buffer->write("\x08decimal3D")->writeUint8(3)->writeInt32(5);
        $buffer->write("\x05bytesx")->writeUint32(3)->write('raw');
        $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);

        Assert::same(Fields::readTable($buffer), [
            'int8' => -1,
            'uint8' => 255,
            'int16' => -2,
            'uint16' => 65535,
            'uint32' => 4_294_967_295,
            'float' => 0.5,
            'decimal2' => '-123.45',
            'decimal3' => '0.005',
            'bytes' => 'raw',
        ]);
    }

    public function failsOnAnUnknownType(): void
    {
        $buffer = new Buffer();
        $buffer->write("\0\0\0\x03\x01k?");

        Expect::exception(ProtocolError::class)->withMessage('Unknown field type "?".');

        Fields::readTable($buffer);
    }

    public function failsOnATableWhoseLastEntryRunsPastItsLength(): void
    {
        $buffer = new Buffer();
        // A table of 3 bytes, the entry k = true takes 4, the byte after it belongs to what holds the table.
        $buffer->write("\0\0\0\x03\x01kt\x01next");

        Expect::exception(ProtocolError::class)->withMessage('A table of 3 bytes holds 4.');

        Fields::readTable($buffer);
    }

    public function failsOnAnArrayWhoseLastValueRunsPastItsLength(): void
    {
        $buffer = new Buffer();
        // A table of 9 bytes with the entry a = an array of 1 byte, which holds true of 2.
        $buffer->write("\0\0\0\x09\x01aA\0\0\0\x01t\x01");

        Expect::exception(ProtocolError::class)->withMessage('An array of 1 bytes holds 2.');

        Fields::readTable($buffer);
    }

    public function failsOnAValueItCannotWrite(): void
    {
        Expect::exception(\ValueError::class)->withMessage('A field table cannot hold a value of type stdClass.');

        Fields::writeTable(new Buffer(), ['object' => new \stdClass()]);
    }

    public function failsOnAShortStringLongerThan255Bytes(): void
    {
        Expect::exception(\ValueError::class);

        Fields::writeShortString(new Buffer(), str_repeat('x', 256));
    }

    /**
     * @param array<array-key, mixed> $table
     * @return array<array-key, mixed>
     */
    private static function roundTrip(array $table): array
    {
        $buffer = new Buffer();
        Fields::writeTable($buffer, $table);
        $read = Fields::readTable($buffer);
        Assert::same($buffer->count(), 0);

        return $read;
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal\Protocol;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Io\Buffer;

#[Test]
final class FieldTableTest
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
        writeFieldValue($buffer, []);

        Assert::same($buffer->drain(), "F\0\0\0\0");
    }

    public function writesIntegerKeysAsStrings(): void
    {
        $buffer = new Buffer();
        writeTable($buffer, [7 => 'seven']);

        Assert::same($buffer->drain(), "\0\0\0\x0C\x017S\0\0\0\x05seven");
    }

    public function readsTheTypesOfOtherClients(): void
    {
        $buffer = new Buffer();
        $buffer->write('b')->writeInt8(-1);
        $buffer->write('B')->writeUint8(255);
        $buffer->write('s')->writeInt16(-2);
        $buffer->write('u')->writeUint16(65535);
        $buffer->write('i')->writeUint32(4_294_967_295);
        $buffer->write('f')->writeFloat(0.5);
        $buffer->write('D')->writeUint8(2)->writeInt32(-12_345);
        $buffer->write('D')->writeUint8(3)->writeInt32(5);
        $buffer->write('x')->writeUint32(3)->write('raw');

        $values = [];

        while ($buffer->count() > 0) {
            $values[] = readFieldValue($buffer);
        }

        Assert::same($values, [-1, 255, -2, 65535, 4_294_967_295, 0.5, '-123.45', '0.005', 'raw']);
    }

    public function failsOnAnUnknownType(): void
    {
        $buffer = new Buffer();
        $buffer->write('?');

        Expect::exception(ProtocolError::class)->withMessage('Unknown field type "?".');

        readFieldValue($buffer);
    }

    public function failsOnAValueItCannotWrite(): void
    {
        Expect::exception(\ValueError::class)->withMessage('A field table cannot hold a value of type stdClass.');

        writeFieldValue(new Buffer(), new \stdClass());
    }

    public function failsOnAShortStringLongerThan255Bytes(): void
    {
        Expect::exception(\ValueError::class);

        writeShortString(new Buffer(), str_repeat('x', 256));
    }

    /**
     * @param array<array-key, mixed> $table
     * @return array<array-key, mixed>
     */
    private static function roundTrip(array $table): array
    {
        $buffer = new Buffer();
        writeTable($buffer, $table);
        $read = readTable($buffer);
        Assert::same($buffer->count(), 0);

        return $read;
    }
}

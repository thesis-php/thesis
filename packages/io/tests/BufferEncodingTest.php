<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Buffer::class)]
final class BufferEncodingTest
{
    /**
     * @return iterable<string, array{
     *     \Closure(Buffer): Buffer,
     *     \Closure(Buffer): (int|float),
     *     int|float,
     *     string,
     *     string,
     * }>
     */
    public static function fixedSizeValues(): iterable
    {
        $int8 = static fn(int $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeInt8($v),
            static fn(Buffer $b) => $b->readInt8(),
            $v,
            $big,
            $little,
        ];
        yield 'int8 min' => $int8(-128, '80', '80');
        yield 'int8 -1' => $int8(-1, 'ff', 'ff');
        yield 'int8 0' => $int8(0, '00', '00');
        yield 'int8 max' => $int8(127, '7f', '7f');

        $uint8 = static fn(int $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeUint8($v),
            static fn(Buffer $b) => $b->readUint8(),
            $v,
            $big,
            $little,
        ];
        yield 'uint8 0' => $uint8(0, '00', '00');
        yield 'uint8 128' => $uint8(128, '80', '80');
        yield 'uint8 max' => $uint8(255, 'ff', 'ff');

        $int16 = static fn(int $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeInt16($v),
            static fn(Buffer $b) => $b->readInt16(),
            $v,
            $big,
            $little,
        ];
        yield 'int16 min' => $int16(-32_768, '8000', '0080');
        yield 'int16 -2' => $int16(-2, 'fffe', 'feff');
        yield 'int16 0' => $int16(0, '0000', '0000');
        yield 'int16 258' => $int16(258, '0102', '0201');
        yield 'int16 max' => $int16(32_767, '7fff', 'ff7f');

        $uint16 = static fn(int $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeUint16($v),
            static fn(Buffer $b) => $b->readUint16(),
            $v,
            $big,
            $little,
        ];
        yield 'uint16 0' => $uint16(0, '0000', '0000');
        yield 'uint16 258' => $uint16(258, '0102', '0201');
        yield 'uint16 32768' => $uint16(32_768, '8000', '0080');
        yield 'uint16 max' => $uint16(65_535, 'ffff', 'ffff');

        $int32 = static fn(int $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeInt32($v),
            static fn(Buffer $b) => $b->readInt32(),
            $v,
            $big,
            $little,
        ];
        yield 'int32 min' => $int32(-2_147_483_648, '80000000', '00000080');
        yield 'int32 -2' => $int32(-2, 'fffffffe', 'feffffff');
        yield 'int32 0' => $int32(0, '00000000', '00000000');
        yield 'int32 16909060' => $int32(16_909_060, '01020304', '04030201');
        yield 'int32 max' => $int32(2_147_483_647, '7fffffff', 'ffffff7f');

        $uint32 = static fn(int $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeUint32($v),
            static fn(Buffer $b) => $b->readUint32(),
            $v,
            $big,
            $little,
        ];
        yield 'uint32 0' => $uint32(0, '00000000', '00000000');
        yield 'uint32 16909060' => $uint32(16_909_060, '01020304', '04030201');
        yield 'uint32 2147483648' => $uint32(2_147_483_648, '80000000', '00000080');
        yield 'uint32 max' => $uint32(4_294_967_295, 'ffffffff', 'ffffffff');

        $int64 = static fn(int $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeInt64($v),
            static fn(Buffer $b) => $b->readInt64(),
            $v,
            $big,
            $little,
        ];
        yield 'int64 min' => $int64(PHP_INT_MIN, '8000000000000000', '0000000000000080');
        yield 'int64 -2' => $int64(-2, 'fffffffffffffffe', 'feffffffffffffff');
        yield 'int64 0' => $int64(0, '0000000000000000', '0000000000000000');
        yield 'int64 72623859790382856' => $int64(0x01_02_03_04_05_06_07_08, '0102030405060708', '0807060504030201');
        yield 'int64 max' => $int64(PHP_INT_MAX, '7fffffffffffffff', 'ffffffffffffff7f');

        $uint64 = static function (int $v, string $big, string $little) {
            \assert($v >= 0);

            return [
                static fn(Buffer $b) => $b->writeUint64($v),
                static fn(Buffer $b) => $b->readUint64(),
                $v,
                $big,
                $little,
            ];
        };
        yield 'uint64 0' => $uint64(0, '0000000000000000', '0000000000000000');
        yield 'uint64 72623859790382856' => $uint64(0x01_02_03_04_05_06_07_08, '0102030405060708', '0807060504030201');
        yield 'uint64 PHP_INT_MAX' => $uint64(PHP_INT_MAX, '7fffffffffffffff', 'ffffffffffffff7f');

        $float = static fn(float $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeFloat($v),
            static fn(Buffer $b) => $b->readFloat(),
            $v,
            $big,
            $little,
        ];
        yield 'float 0' => $float(0.0, '00000000', '00000000');
        yield 'float 1.5' => $float(1.5, '3fc00000', '0000c03f');
        yield 'float -2.25' => $float(-2.25, 'c0100000', '000010c0');

        $double = static fn(float $v, string $big, string $little) => [
            static fn(Buffer $b) => $b->writeDouble($v),
            static fn(Buffer $b) => $b->readDouble(),
            $v,
            $big,
            $little,
        ];
        yield 'double 0' => $double(0.0, '0000000000000000', '0000000000000000');
        yield 'double 1.5' => $double(1.5, '3ff8000000000000', '000000000000f83f');
        yield 'double -0.1' => $double(-0.1, 'bfb999999999999a', '9a9999999999b9bf');
    }

    /**
     * @param \Closure(Buffer): Buffer $write
     * @param \Closure(Buffer): (int|float) $read
     */
    #[DataProvider('fixedSizeValues')]
    public function writesBigEndian(
        \Closure $write,
        \Closure $read,
        int|float $value,
        string $big,
        string $little,
    ): void {
        $buffer = new Buffer(Order::Big);

        $result = $write($buffer);

        Assert::same($result, $buffer);
        Assert::same(bin2hex($buffer->drain()), $big);
    }

    /**
     * @param \Closure(Buffer): Buffer $write
     * @param \Closure(Buffer): (int|float) $read
     */
    #[DataProvider('fixedSizeValues')]
    public function writesLittleEndian(
        \Closure $write,
        \Closure $read,
        int|float $value,
        string $big,
        string $little,
    ): void {
        $buffer = new Buffer(Order::Little);

        $write($buffer);

        Assert::same(bin2hex($buffer->drain()), $little);
    }

    /**
     * @param \Closure(Buffer): Buffer $write
     * @param \Closure(Buffer): (int|float) $read
     */
    #[DataProvider('fixedSizeValues')]
    public function readsBigEndian(
        \Closure $write,
        \Closure $read,
        int|float $value,
        string $big,
        string $little,
    ): void {
        $buffer = new Buffer(Order::Big, Hex::bytes($big . 'aa'));

        $result = $read($buffer);

        Assert::same($result, $value);
        Assert::same($buffer->drain(), "\xAA");
    }

    /**
     * @param \Closure(Buffer): Buffer $write
     * @param \Closure(Buffer): (int|float) $read
     */
    #[DataProvider('fixedSizeValues')]
    public function readsLittleEndian(
        \Closure $write,
        \Closure $read,
        int|float $value,
        string $big,
        string $little,
    ): void {
        $buffer = new Buffer(Order::Little, Hex::bytes($little . 'aa'));

        $result = $read($buffer);

        Assert::same($result, $value);
        Assert::same($buffer->drain(), "\xAA");
    }

    public function usesNetworkOrderByDefault(): void
    {
        $buffer = new Buffer();

        $buffer->writeUint32(16_909_060);

        Assert::same($buffer->order, Order::Network);
        Assert::same(bin2hex($buffer->drain()), '01020304');
    }

    public function readsSequentialValuesAtCursor(): void
    {
        $buffer = new Buffer(bytes: Hex::bytes('0102030405060708090a0b0c0d0e0f'));

        Assert::same($buffer->readUint8(), 0x01);
        Assert::same($buffer->readUint16(), 0x0203);
        Assert::same($buffer->readUint32(), 0x04_05_06_07);
        Assert::same($buffer->readInt64(), 0x08_09_0A_0B_0C_0D_0E_0F);
        Assert::count($buffer, 0);
    }

    public function writesFloatWithSinglePrecision(): void
    {
        $buffer = new Buffer();

        $buffer->writeFloat(0.1);

        Assert::same($buffer->readFloat(), 0.10000000149011612);
    }

    /**
     * @return iterable<string, array{\Closure(Buffer): mixed, int}>
     */
    public static function truncatedReads(): iterable
    {
        yield 'int8' => [static fn(Buffer $b): int => $b->readInt8(), 0];
        yield 'uint8' => [static fn(Buffer $b): int => $b->readUint8(), 0];
        yield 'int16' => [static fn(Buffer $b): int => $b->readInt16(), 1];
        yield 'uint16' => [static fn(Buffer $b): int => $b->readUint16(), 1];
        yield 'int32' => [static fn(Buffer $b): int => $b->readInt32(), 3];
        yield 'uint32' => [static fn(Buffer $b): int => $b->readUint32(), 3];
        yield 'int64' => [static fn(Buffer $b): int => $b->readInt64(), 7];
        yield 'uint64' => [static fn(Buffer $b): int => $b->readUint64(), 7];
        yield 'float' => [static fn(Buffer $b): float => $b->readFloat(), 3];
        yield 'double' => [static fn(Buffer $b): float => $b->readDouble(), 7];
    }

    /**
     * @param \Closure(Buffer): mixed $read
     */
    #[DataProvider('truncatedReads')]
    public function throwsOnTruncatedReadWithoutConsuming(\Closure $read, int $available): void
    {
        $buffer = new Buffer(bytes: str_repeat("\x01", $available));
        Expect
            ::exception(UnexpectedEof::class)
            ->withMessage(\sprintf('Expected %d bytes, but only %d available.', $available + 1, $available))
        ;

        try {
            $read($buffer);
        } finally {
            Assert::count($buffer, $available);
        }
    }

    public function throwsOnUint64AboveIntMaxWithoutConsuming(): void
    {
        $buffer = new Buffer(bytes: Hex::bytes('8000000000000000'));
        Expect::exception(\OverflowException::class)->withMessage('Uint64 value exceeds PHP_INT_MAX.');

        try {
            $buffer->readUint64();
        } finally {
            Assert::count($buffer, 8);
        }
    }

    /**
     * @return iterable<string, array{\Closure(Buffer): mixed}>
     */
    public static function outOfRangeWrites(): iterable
    {
        yield 'int8 below' => [static fn(Buffer $b): Buffer => $b->writeInt8(-129)];
        yield 'int8 above' => [static fn(Buffer $b): Buffer => $b->writeInt8(128)];
        yield 'uint8 below' => [static fn(Buffer $b): Buffer => $b->writeUint8(-1)];
        yield 'uint8 above' => [static fn(Buffer $b): Buffer => $b->writeUint8(256)];
        yield 'int16 below' => [static fn(Buffer $b): Buffer => $b->writeInt16(-32_769)];
        yield 'int16 above' => [static fn(Buffer $b): Buffer => $b->writeInt16(32_768)];
        yield 'uint16 below' => [static fn(Buffer $b): Buffer => $b->writeUint16(-1)];
        yield 'uint16 above' => [static fn(Buffer $b): Buffer => $b->writeUint16(65_536)];
        yield 'int32 below' => [static fn(Buffer $b): Buffer => $b->writeInt32(-2_147_483_649)];
        yield 'int32 above' => [static fn(Buffer $b): Buffer => $b->writeInt32(2_147_483_648)];
        yield 'uint32 below' => [static fn(Buffer $b): Buffer => $b->writeUint32(-1)];
        yield 'uint32 above' => [static fn(Buffer $b): Buffer => $b->writeUint32(4_294_967_296)];
    }

    /**
     * @param \Closure(Buffer): mixed $write
     */
    #[DataProvider('outOfRangeWrites')]
    public function assertsRangeOnWrite(\Closure $write): void
    {
        self::requireAssertions();
        Expect::exception(\AssertionError::class);

        $write(new Buffer());
    }

    private static function requireAssertions(): void
    {
        if (\ini_get('zend.assertions') !== '1') {
            throw new SkipTest('Requires zend.assertions=1.');
        }
    }
}

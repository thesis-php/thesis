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
final class BufferReserveTest
{
    public function reservesZeroBytesAndReturnsMark(): void
    {
        $buffer = new Buffer(bytes: 'ab');

        $mark = $buffer->reserve(3);

        Assert::same($mark, 2);
        Assert::same($buffer->drain(), "ab\0\0\0");
    }

    public function reservesNothing(): void
    {
        $buffer = new Buffer(bytes: 'ab');

        $mark = $buffer->reserve(0);

        Assert::same($mark, 2);
        Assert::same($buffer->sizeSince($mark), 0);
    }

    public function measuresSizeSinceMark(): void
    {
        $buffer = new Buffer(bytes: 'x');
        $mark = $buffer->reserve(4);

        $buffer->write('hello');

        Assert::same($buffer->sizeSince($mark), 9);
    }

    public function patchesLengthPrefix(): void
    {
        $buffer = new Buffer();
        $buffer->writeUint8(7);
        $mark = $buffer->reserve(4);
        $buffer->write('hello');

        $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);

        Assert::same(bin2hex($buffer->drain()), '0700000005' . bin2hex('hello'));
    }

    /**
     * @return iterable<string, array{\Closure(Buffer, int): void, int, int, string, string}>
     */
    public static function patches(): iterable
    {
        $uint8 = static function (Buffer $b, int $v): void {
            $b->patchUint8(1, $v);
        };
        yield 'uint8' => [$uint8, 1, 0xAB, 'ab', 'ab'];

        $uint16 = static function (Buffer $b, int $v): void {
            $b->patchUint16(1, $v);
        };
        yield 'uint16' => [$uint16, 2, 0x0102, '0102', '0201'];
        yield 'uint16 max' => [$uint16, 2, 0xFFFF, 'ffff', 'ffff'];

        $uint32 = static function (Buffer $b, int $v): void {
            $b->patchUint32(1, $v);
        };
        yield 'uint32' => [$uint32, 4, 0x01_02_03_04, '01020304', '04030201'];
        yield 'uint32 max' => [$uint32, 4, 0xFF_FF_FF_FF, 'ffffffff', 'ffffffff'];

        $uint64 = static function (Buffer $b, int $v): void {
            \assert($v >= 0);
            $b->patchUint64(1, $v);
        };
        yield 'uint64' => [$uint64, 8, 0x01_02_03_04_05_06_07_08, '0102030405060708', '0807060504030201'];
    }

    /**
     * @param \Closure(Buffer, int): void $patch
     * @param non-negative-int $size
     */
    #[DataProvider('patches')]
    public function patchesInBigEndian(\Closure $patch, int $size, int $value, string $big, string $little): void
    {
        $buffer = new Buffer(Order::Big, 'L');
        $buffer->reserve($size);
        $buffer->write('R');

        $patch($buffer, $value);

        Assert::same(bin2hex($buffer->drain()), '4c' . $big . '52');
    }

    /**
     * @param \Closure(Buffer, int): void $patch
     * @param non-negative-int $size
     */
    #[DataProvider('patches')]
    public function patchesInLittleEndian(\Closure $patch, int $size, int $value, string $big, string $little): void
    {
        $buffer = new Buffer(Order::Little, 'L');
        $buffer->reserve($size);
        $buffer->write('R');

        $patch($buffer, $value);

        Assert::same(bin2hex($buffer->drain()), '4c' . $little . '52');
    }

    public function patchesWithUnreadPrefixConsumed(): void
    {
        $buffer = new Buffer(bytes: 'abc');
        $buffer->read(2);
        $mark = $buffer->reserve(2);

        $buffer->patchUint16($mark, 0x0102);

        Assert::same(bin2hex($buffer->drain()), '630102');
    }

    public function patchDoesNotAffectCopies(): void
    {
        $buffer = new Buffer();
        $mark = $buffer->reserve(1);
        $copy = $buffer->peek(1);

        $buffer->patchUint8($mark, 0xFF);

        Assert::same($copy, "\0");
        Assert::same($buffer->drain(), "\xFF");
    }

    /**
     * @return iterable<string, array{\Closure(Buffer): mixed}>
     */
    public static function invalidPatches(): iterable
    {
        yield 'uint8 below' => [static function (Buffer $b): void {
            $b->patchUint8($b->reserve(1), -1);
        }];
        yield 'uint8 above' => [static function (Buffer $b): void {
            $b->patchUint8($b->reserve(1), 256);
        }];
        yield 'uint16 below' => [static function (Buffer $b): void {
            $b->patchUint16($b->reserve(2), -1);
        }];
        yield 'uint16 above' => [static function (Buffer $b): void {
            $b->patchUint16($b->reserve(2), 65_536);
        }];
        yield 'uint32 below' => [static function (Buffer $b): void {
            $b->patchUint32($b->reserve(4), -1);
        }];
        yield 'uint32 above' => [static function (Buffer $b): void {
            $b->patchUint32($b->reserve(4), 4_294_967_296);
        }];
        yield 'uint8 mark at end' => [static function (Buffer $b): void {
            $b->patchUint8($b->reserve(0), 0);
        }];
        yield 'uint16 mark a byte short' => [static function (Buffer $b): void {
            $b->patchUint16($b->reserve(1), 0);
        }];
        yield 'uint32 mark a byte short' => [static function (Buffer $b): void {
            $b->patchUint32($b->reserve(3), 0);
        }];
        yield 'uint64 mark a byte short' => [static function (Buffer $b): void {
            $b->patchUint64($b->reserve(7), 0);
        }];
        yield 'size since mark beyond end' => [static function (Buffer $b): void {
            $b->sizeSince(1);
        }];
    }

    /**
     * @param \Closure(Buffer): mixed $patch
     */
    #[DataProvider('invalidPatches')]
    public function assertsInvalidPatch(\Closure $patch): void
    {
        if (\ini_get('zend.assertions') !== '1') {
            throw new SkipTest('Requires zend.assertions=1.');
        }

        Expect::exception(\AssertionError::class);

        $patch(new Buffer());
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Io;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Buffer::class)]
final class BufferVarintTest
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function uvarints(): iterable
    {
        yield '0' => [0, '00'];
        yield '1' => [1, '01'];
        yield '127' => [127, '7f'];
        yield '128' => [128, '8001'];
        yield '300' => [300, 'ac02'];
        yield '16383' => [16_383, 'ff7f'];
        yield '16384' => [16_384, '808001'];
        yield '2^21 - 1' => [2_097_151, 'ffff7f'];
        yield '2^21' => [2_097_152, '80808001'];
        yield 'uint32 max' => [4_294_967_295, 'ffffffff0f'];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX, 'ffffffffffffffff7f'];
        yield '-1 as uint64 max' => [-1, 'ffffffffffffffffff01'];
        yield 'PHP_INT_MIN as 2^63' => [PHP_INT_MIN, '80808080808080808001'];
    }

    #[DataProvider('uvarints')]
    public function writesUvarint(int $value, string $hex): void
    {
        $buffer = new Buffer();

        $result = $buffer->writeUvarint($value);

        Assert::same($result, $buffer);
        Assert::same(bin2hex($buffer->drain()), $hex);
    }

    #[DataProvider('uvarints')]
    public function readsUvarint(int $value, string $hex): void
    {
        $buffer = new Buffer(bytes: Hex::bytes($hex . 'aa'));

        $result = $buffer->readUvarint();

        Assert::same($result, $value);
        Assert::same($buffer->drain(), "\xAA");
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function varints(): iterable
    {
        yield '0' => [0, '00'];
        yield '-1' => [-1, '01'];
        yield '1' => [1, '02'];
        yield '-2' => [-2, '03'];
        yield '-64' => [-64, '7f'];
        yield '64' => [64, '8001'];
        yield 'int32 max' => [2_147_483_647, 'feffffff0f'];
        yield 'int32 min' => [-2_147_483_648, 'ffffffff0f'];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX, 'feffffffffffffffff01'];
        yield 'PHP_INT_MIN' => [PHP_INT_MIN, 'ffffffffffffffffff01'];
    }

    #[DataProvider('varints')]
    public function writesZigZagVarint(int $value, string $hex): void
    {
        $buffer = new Buffer();

        $result = $buffer->writeVarint($value);

        Assert::same($result, $buffer);
        Assert::same(bin2hex($buffer->drain()), $hex);
    }

    #[DataProvider('varints')]
    public function readsZigZagVarint(int $value, string $hex): void
    {
        $buffer = new Buffer(bytes: Hex::bytes($hex . 'aa'));

        $result = $buffer->readVarint();

        Assert::same($result, $value);
        Assert::same($buffer->drain(), "\xAA");
    }

    public function roundTripsRandomValues(): void
    {
        $random = new Randomizer(new Mt19937(42));
        $buffer = new Buffer();
        $values = [];

        for ($i = 0; $i < 1000; ++$i) {
            $values[] = $random->getInt(PHP_INT_MIN, PHP_INT_MAX) >> $random->getInt(0, 63);
        }

        foreach ($values as $value) {
            $buffer->writeUvarint($value)->writeVarint($value);
        }

        foreach ($values as $value) {
            Assert::same($buffer->readUvarint(), $value);
            Assert::same($buffer->readVarint(), $value);
        }
        Assert::count($buffer, 0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function overflowingUvarints(): iterable
    {
        yield '11 bytes' => [str_repeat('ff', 10) . '01'];
        yield '10th byte above 1' => [str_repeat('ff', 9) . '02'];
        yield '10th byte with continuation' => [str_repeat('ff', 9) . '81'];
    }

    #[DataProvider('overflowingUvarints')]
    public function throwsOnUvarintOverflowWithoutConsuming(string $hex): void
    {
        $buffer = new Buffer(bytes: Hex::bytes($hex));
        Expect::exception(\OverflowException::class)->withMessage('Varint overflows 64 bits.');

        try {
            $buffer->readUvarint();
        } finally {
            Assert::count($buffer, intdiv(\strlen($hex), 2));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truncatedUvarints(): iterable
    {
        yield 'empty' => [''];
        yield 'single continuation byte' => ['80'];
        yield '3 continuation bytes' => ['ffffff'];
        yield '9 continuation bytes' => [str_repeat('ff', 9)];
    }

    #[DataProvider('truncatedUvarints')]
    public function throwsOnTruncatedUvarintWithoutConsuming(string $hex): void
    {
        $buffer = new Buffer(bytes: Hex::bytes($hex));
        Expect::exception(UnexpectedEof::class)->withMessage('Unexpected end of varint.');

        try {
            $buffer->readUvarint();
        } finally {
            Assert::count($buffer, intdiv(\strlen($hex), 2));
        }
    }

    public function readsUvarintAtCursor(): void
    {
        $buffer = new Buffer(bytes: Hex::bytes('ffac0205'));
        $buffer->skip(1);

        Assert::same($buffer->readUvarint(), 300);
        Assert::same($buffer->readUvarint(), 5);
    }
}

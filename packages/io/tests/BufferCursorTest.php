<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Buffer::class)]
final class BufferCursorTest
{
    public function isEmptyByDefault(): void
    {
        $buffer = new Buffer();

        Assert::count($buffer, 0);
        Assert::same($buffer->drain(), '');
    }

    public function countsUnreadBytes(): void
    {
        $buffer = new Buffer(bytes: 'abcdef');

        $buffer->read(2);
        $buffer->write('gh');

        Assert::count($buffer, 6);
    }

    public function readsExactlyRequestedBytes(): void
    {
        $buffer = new Buffer(bytes: 'abcdef');

        Assert::same($buffer->read(2), 'ab');
        Assert::same($buffer->read(4), 'cdef');
        Assert::count($buffer, 0);
    }

    public function throwsOnReadBeyondEndWithoutConsuming(): void
    {
        $buffer = new Buffer(bytes: 'ab');
        Expect::exception(UnexpectedEof::class)->withMessage('Expected 3 bytes, but only 2 available.');

        try {
            $buffer->read(3);
        } finally {
            Assert::count($buffer, 2);
        }
    }

    public function skipsBytes(): void
    {
        $buffer = new Buffer(bytes: 'abcdef');

        $buffer->skip(0);
        $buffer->skip(2);

        Assert::same($buffer->read(1), 'c');
    }

    public function throwsOnSkipBeyondEndWithoutConsuming(): void
    {
        $buffer = new Buffer(bytes: 'ab');
        Expect::exception(UnexpectedEof::class);

        try {
            $buffer->skip(3);
        } finally {
            Assert::count($buffer, 2);
        }
    }

    public function drainsUnreadBytes(): void
    {
        $buffer = new Buffer(bytes: 'abcdef');
        $buffer->read(2);

        $bytes = $buffer->drain();

        Assert::same($bytes, 'cdef');
        Assert::count($buffer, 0);
    }

    public function writesAfterDrainFromStart(): void
    {
        $buffer = new Buffer(bytes: 'abc');
        $buffer->read(1);
        $buffer->drain();

        $buffer->write('xy');

        Assert::same($buffer->drain(), 'xy');
    }

    public function writesRawBytes(): void
    {
        $buffer = new Buffer();

        $result = $buffer->write('ab')->write('')->write('c');

        Assert::same($result, $buffer);
        Assert::same($buffer->drain(), 'abc');
    }

    public function peeksWithoutConsuming(): void
    {
        $buffer = new Buffer(bytes: 'abcdef');
        $buffer->read(1);

        Assert::same($buffer->peek(2), 'bc');
        Assert::same($buffer->peek(2, 3), 'ef');
        Assert::count($buffer, 5);
    }

    public function throwsOnPeekBeyondEnd(): void
    {
        $buffer = new Buffer(bytes: 'abc');
        Expect::exception(UnexpectedEof::class)->withMessage('Expected 4 bytes, but only 3 available.');

        $buffer->peek(2, 2);
    }

    public function slicesIntoBufferOfSameOrder(): void
    {
        $buffer = new Buffer(Order::Little, Hex::bytes('ff0201ee'));
        $buffer->skip(1);

        $slice = $buffer->slice(2);

        Assert::same($slice->order, Order::Little);
        Assert::same($slice->readUint16(), 0x0102);
        Assert::count($slice, 0);
        Assert::same($buffer->drain(), "\xEE");
    }

    public function slicesEmptyBuffer(): void
    {
        $buffer = new Buffer(bytes: 'ab');

        $slice = $buffer->slice(0);

        Assert::count($slice, 0);
        Assert::count($buffer, 2);
    }

    public function sliceInheritsChunkSize(): void
    {
        $reader = new ChunkedReader('abcdef');
        $slice = new Buffer(bytes: 'xy', chunkSize: 3)->slice(1);
        $slice->read(1);

        $slice->fill($reader, 1);

        Assert::same($reader->requests, [3]);
    }

    public function throwsOnSliceBeyondEndWithoutConsuming(): void
    {
        $buffer = new Buffer(bytes: 'ab');
        Expect::exception(UnexpectedEof::class);

        try {
            $buffer->slice(3);
        } finally {
            Assert::count($buffer, 2);
        }
    }

    public function readsUntilDelimiter(): void
    {
        $buffer = new Buffer(bytes: "PING\r\n\r\n+OK\r\ntail");

        Assert::same($buffer->readUntil("\r\n"), 'PING');
        Assert::same($buffer->readUntil("\r\n"), '');
        Assert::same($buffer->readUntil("\r\n"), '+OK');
        Assert::same($buffer->drain(), 'tail');
    }

    public function searchesDelimiterFromCursor(): void
    {
        $buffer = new Buffer(bytes: 'a;b;c');
        $buffer->read(2);

        Assert::same($buffer->readUntil(';'), 'b');
    }

    public function returnsNullWithoutConsumingWhenDelimiterIsMissing(): void
    {
        $buffer = new Buffer(bytes: "partial\r");

        $result = $buffer->readUntil("\r\n");

        Assert::null($result);
        Assert::count($buffer, 8);
    }

    public function readsEmptyStringForZeroLength(): void
    {
        $buffer = new Buffer(bytes: 'ab');

        Assert::same($buffer->read(0), '');
        Assert::same($buffer->peek(0, 2), '');
        Assert::count($buffer, 2);
    }
}

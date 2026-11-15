<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Buffer::class)]
final class BufferIoTest
{
    public function fillDoesNotReadWhenEnoughBuffered(): void
    {
        $reader = new ChunkedReader('xyz');
        $buffer = new Buffer(bytes: 'abc');

        $buffer->fill($reader, 3);
        $buffer->fill($reader, 0);

        Assert::same($reader->requests, []);
    }

    public function fillReadsUntilEnoughBuffered(): void
    {
        $reader = new ChunkedReader('defghij', chunkSize: 2);
        $buffer = new Buffer(bytes: 'abc', chunkSize: 4);

        $buffer->fill($reader, 8);

        Assert::same($reader->requests, [5, 4, 4]);
        Assert::same($buffer->drain(), 'abcdefghi');
    }

    public function fillRequestsMissingBytesWhenAboveChunkSize(): void
    {
        $reader = new ChunkedReader(str_repeat('x', 100));
        $buffer = new Buffer(chunkSize: 16);

        $buffer->fill($reader, 100);

        Assert::same($reader->requests, [100]);
        Assert::count($buffer, 100);
    }

    public function fillThrowsOnEof(): void
    {
        $buffer = new Buffer();
        Expect::exception(UnexpectedEof::class);

        $buffer->fill(new ChunkedReader('ab'), 3);
    }

    public function fillKeepsUnreadBytesAfterCompaction(): void
    {
        $data = random_bytes(200_000);
        $reader = new ChunkedReader(substr($data, 70_000), chunkSize: 1000);
        $buffer = new Buffer(bytes: substr($data, 0, 70_000));
        $buffer->read(69_990);

        $buffer->fill($reader, 50_000);

        Assert::same($buffer->read(50_000), substr($data, 69_990, 50_000));
    }

    public function fillKeepsUnreadBytesBelowCompactionThreshold(): void
    {
        $buffer = new Buffer(bytes: 'abcdef');
        $buffer->read(4);

        $buffer->fill(new ChunkedReader('gh'), 4);

        Assert::same($buffer->drain(), 'efgh');
    }

    public function fillsAfterEverythingIsConsumed(): void
    {
        $buffer = new Buffer(bytes: 'abc');
        $buffer->read(3);

        $buffer->fill(new ChunkedReader('de'), 2);

        Assert::same($buffer->drain(), 'de');
    }

    public function fillUntilDoesNotReadWhenDelimiterIsBuffered(): void
    {
        $reader = new ChunkedReader('xyz');
        $buffer = new Buffer(bytes: "PING\r\n");

        $buffer->fillUntil($reader, "\r\n");

        Assert::same($reader->requests, []);
        Assert::same($buffer->readUntil("\r\n"), 'PING');
    }

    public function fillUntilFindsDelimiterSplitBetweenChunks(): void
    {
        $reader = new ChunkedReader("MSG foo 1 5\r\nhello\r\n", chunkSize: 1);
        $buffer = new Buffer();

        $buffer->fillUntil($reader, "\r\n");

        Assert::same($buffer->readUntil("\r\n"), 'MSG foo 1 5');
        Assert::count($reader->requests, 13);
    }

    public function fillUntilSearchesFromCursor(): void
    {
        $buffer = new Buffer(bytes: "a\nb");
        $buffer->read(2);

        $buffer->fillUntil(new ChunkedReader("c\n"), "\n");

        Assert::same($buffer->readUntil("\n"), 'bc');
    }

    public function fillUntilRequestsAtMostRemainingLimit(): void
    {
        $reader = new ChunkedReader("abcdef\n", chunkSize: 4);
        $buffer = new Buffer(bytes: 'xy', chunkSize: 4);

        $buffer->fillUntil($reader, "\n", maxLength: 10);

        Assert::same($reader->requests, [4, 4]);
        Assert::same($buffer->readUntil("\n"), 'xyabcdef');
    }

    public function fillUntilAcceptsDelimiterEndingExactlyAtLimit(): void
    {
        $buffer = new Buffer();

        $buffer->fillUntil(new ChunkedReader("abc\n"), "\n", maxLength: 4);

        Assert::same($buffer->readUntil("\n"), 'abc');
    }

    public function fillUntilThrowsWhenDelimiterIsNotFoundWithinLimit(): void
    {
        $buffer = new Buffer();
        Expect::exception(\OverflowException::class)->withMessage('Delimiter not found within 4 bytes.');

        $buffer->fillUntil(new ChunkedReader(str_repeat('x', 100)), "\n", maxLength: 4);
    }

    public function fillUntilThrowsWhenBufferedDelimiterIsBeyondLimit(): void
    {
        $reader = new ChunkedReader('');
        $buffer = new Buffer(bytes: "abcd\n");
        Expect::exception(\OverflowException::class);

        $buffer->fillUntil($reader, "\n", maxLength: 4);
    }

    public function fillUntilThrowsOnEof(): void
    {
        $buffer = new Buffer();
        Expect::exception(UnexpectedEof::class);

        $buffer->fillUntil(new ChunkedReader('abc'), "\n");
    }

    public function flushWritesUnreadBytesAndClears(): void
    {
        $writer = new RecordingWriter();
        $buffer = new Buffer(bytes: 'abc');
        $buffer->read(1);

        $buffer->flush($writer);

        Assert::same($writer->writes, ['bc']);
        Assert::count($buffer, 0);
    }

    public function flushThatFailedKeepsTheBytesBeforeThoseWrittenMeanwhile(): void
    {
        $buffer = new Buffer(bytes: 'abc');
        $buffer->read(1);
        $writer = new FailingWriter(static function () use ($buffer) {
            $buffer->write('d');
        });

        try {
            $buffer->flush($writer);
            Assert::fail('WriteFailed expected.');
        } catch (WriteFailed) {
        }

        Assert::same($buffer->drain(), 'bcd');
    }

    public function flushDoesNotWriteEmptyBuffer(): void
    {
        $writer = new RecordingWriter();
        $buffer = new Buffer(bytes: 'abc');
        $buffer->read(3);

        $buffer->flush($writer);

        Assert::same($writer->writes, []);
    }
}

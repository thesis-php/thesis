<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal\Http1;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Io\Buffer;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\ReadFailed;

#[Test]
#[Covers(FixedLengthReader::class)]
final class FixedLengthReaderTest
{
    public function leavesNextMessageInBuffer(): void
    {
        $buffer = new Buffer(bytes: 'abcnext');
        $source = new ScriptedReader('');
        $reader = new FixedLengthReader($buffer, $source, size: 3);

        Assert::same($reader->read(1), 'a');
        Assert::same(readAll($reader), 'bc');
        Assert::same($reader->size, 3);
        Assert::same($buffer->peek(4), 'next');
        Assert::same($source->requests, []);
    }

    public function readAllRejectsAPartiallyReadPayloadAndLeavesTheRemainingBytes(): void
    {
        $reader = new FixedLengthReader(new Buffer(), new ScriptedReader('abc'), size: 3);
        Assert::same($reader->read(1), 'a');
        Expect::exception(\LogicException::class)->withMessage('The body has already been read.');

        try {
            $reader->readAll();
        } finally {
            Assert::same($reader->read(2), 'bc');
        }
    }

    public function readsOnlyTheRemainingBodyBytesFromTheSource(): void
    {
        $source = new ScriptedReader('bcnext');
        $reader = new FixedLengthReader(new Buffer(bytes: 'a'), $source, size: 3);

        Assert::same(readAll($reader), 'abc');
        Assert::same($source->requests, [2]);
        Assert::same(readAll($source), 'next');
    }

    public function failsIfTheSourceEndsEarly(): void
    {
        $reader = new FixedLengthReader(new Buffer(), new ScriptedReader('a'), size: 3);
        $reader->read(3);
        Expect::exception(ReadFailed::class)->withMessage('The body has ended after 1 of 3 bytes.');

        $reader->read(3);
    }

    public function endsAnEmptyBodyWithoutReadingTheSource(): void
    {
        $source = new ScriptedReader('next');
        $reader = new FixedLengthReader(new Buffer(), $source, size: 0);
        Assert::null($reader->read(1));
        Assert::null($reader->read(1));
        Assert::same($source->requests, []);
    }

    public function anInterruptedReadLeavesTheSameBytesToRead(): void
    {
        $source = new ScriptedReader('bcnext');
        $reader = new FixedLengthReader(new Buffer(bytes: 'a'), $source, size: 3);
        Assert::same($reader->read(3), 'a');
        $source->failure = new ReadFailed('Reset.');
        Expect::exception(ReadFailed::class)->withMessage('Reset.');

        try {
            $reader->read(3);
        } finally {
            Assert::same(readAll($reader), 'bc');
            Assert::same($source->requests, [2, 2]);
        }
    }

    public function rejectsAConcurrentReadWithoutMovingTheBoundary(): void
    {
        $buffer = new Buffer();
        $source = new ScriptedReader('acnext');
        $source->beforeRead = static function () {
            \Fiber::suspend();
        };
        $reader = new FixedLengthReader($buffer, $source, size: 3);
        $reading = new \Fiber(static fn() => $reader->read(1));
        $reading->start();
        $buffer->write('b');
        $source->beforeRead = null;
        Expect::exception(ConcurrentRead::class)->withMessage('A read is already in progress on the body.');

        try {
            $reader->read(1);
        } finally {
            $reading->resume();
            Assert::same($reading->getReturn(), 'a');
            Assert::same(readAll($reader), 'bc');
            Assert::same($source->requests, [1, 1]);
        }
    }
}

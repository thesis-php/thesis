<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;
use Thesis\Async\Group;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;
use function Thesis\Time\ms;

#[Test]
#[Covers(Stream::class)]
final class StreamTest
{
    private ?string $file = null;

    #[AfterTest]
    public function removeFile(): void
    {
        if ($this->file !== null && file_exists($this->file)) {
            unlink($this->file);
        }
    }

    public function writesAndReads(): void
    {
        $handle = self::open('php://memory', 'w+');
        $stream = new Stream($handle);
        $stream->write('hello');
        $stream->write(str_repeat('x', 100_000));
        rewind($handle);
        $buffer = new Buffer();

        $buffer->fill($stream, 100_005);

        Assert::same($buffer->read(5), 'hello');
        Assert::same($buffer->drain(), str_repeat('x', 100_000));
    }

    public function readsAtMostMax(): void
    {
        $handle = self::open('php://memory', 'w+');
        fwrite($handle, 'abcdef');
        rewind($handle);
        $stream = new Stream($handle);

        $bytes = $stream->read(4);

        Assert::same($bytes, 'abcd');
    }

    public function throwsOnEof(): void
    {
        $stream = new Stream(self::open('php://memory', 'r'));
        Expect::exception(UnexpectedEof::class)->withMessage('Unexpected end of stream.');

        $stream->read(1);
    }

    public function doesNotReportUnrelatedErrorOnEof(): void
    {
        @trigger_error('unrelated', E_USER_WARNING);
        $stream = new Stream(self::open('php://memory', 'r'));
        Expect::exception(UnexpectedEof::class)->withMessage('Unexpected end of stream.');

        $stream->read(1);
    }

    public function readsMoreThanDefaultSocketChunk(): void
    {
        [$out, $in] = self::pair();
        $out->write(str_repeat('x', 20_000));

        Assert::same(\strlen($in->read(65_536)), 20_000);
    }

    public function readWaitsForThePeer(): void
    {
        [$left, $right] = self::pair();

        $read = group(static function (Group $group) use ($left, $right) {
            $read = $group->spawn(static fn() => $right->read(100));
            delay(ms(1));
            $left->write('late');

            return $read->await();
        });

        Assert::same($read, 'late');
    }

    public function writeWaitsWhileThePeerDoesNotRead(): void
    {
        [$left, $right] = self::pair();
        $data = random_bytes(8_000_000);

        $received = group(static function (Group $group) use ($left, $right, $data) {
            $group->spawn(static fn() => $left->write($data));
            $buffer = new Buffer();
            $buffer->fill($right, 8_000_000);

            return $buffer->drain();
        });

        Assert::true($received === $data);
    }

    public function writesOfConcurrentFibersDoNotInterleave(): void
    {
        [$left, $right] = self::pair();
        $a = str_repeat('a', 2_000_000);
        $b = str_repeat('b', 2_000_000);

        $received = group(static function (Group $group) use ($left, $right, $a, $b) {
            $group->spawn(static fn() => $left->write($a));
            $group->spawn(static fn() => $left->write($b));
            $buffer = new Buffer();
            $buffer->fill($right, 4_000_000);

            return $buffer->drain();
        });

        Assert::true($received === $a . $b);
    }

    public function cancelledReadTakesNothing(): void
    {
        [$left, $right] = self::pair();

        timeoutOr(ms(1), static fn() => $right->read(100));
        $left->write('kept');

        Assert::same($right->read(100), 'kept');
    }

    public function throwsUnexpectedEofOnceThePeerHasClosed(): void
    {
        [$left, $right] = self::pair();
        $left->write('last');
        $left->close();

        Assert::same($right->read(100), 'last');
        Expect::exception(UnexpectedEof::class)->withMessage('Unexpected end of stream.');

        $right->read(100);
    }

    public function closeEndsAWaitingRead(): void
    {
        [$left, $right] = self::pair();
        Expect::exception(StreamClosed::class)->withMessage('The stream was closed while reading.');

        group(static function (Group $group) use ($right) {
            $group->spawn(static fn() => $right->read(1));
            delay(ms(1));
            $right->close();
        });
        unset($left);
    }

    public function throwsStreamClosedAfterClose(): void
    {
        [$left] = self::pair();
        $left->close();

        Assert::true($left->isClosed);
        Expect::exception(StreamClosed::class)->withMessage('The stream is closed.');

        $left->write('x');
    }

    public function secondConcurrentReaderIsAnError(): void
    {
        [$left, $right] = self::pair();
        Expect::exception(\LogicException::class)->withMessage('Another fiber is reading from the stream.');

        group(static function (Group $group) use ($right) {
            $group->spawn(static fn() => $right->read(1));
            delay(ms(1));
            $right->read(1);
        });
        unset($left);
    }

    public function throwsOnFailedWrite(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'thesis-io-');
        \assert(\is_string($file));
        $this->file = $file;
        $stream = new Stream(self::open($file, 'r'));
        Expect::exception(WriteFailed::class);

        $stream->write('abc');
    }

    public function throwsOnFailedRead(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'thesis-io-');
        \assert(\is_string($file));
        $this->file = $file;
        $stream = new Stream(self::open($file, 'w'));
        Expect::exception(ReadFailed::class)->withMessageContaining('Bad file descriptor');

        $stream->read(1);
    }

    public function closesStream(): void
    {
        $handle = self::open('php://memory', 'r');
        $stream = new Stream($handle);

        $stream->close();
        $stream->close();

        Assert::false(\is_resource($handle));
    }

    public function rejectsClosedResource(): void
    {
        $handle = self::open('php://memory', 'r');
        fclose($handle);
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Expected a stream resource');

        new Stream($handle);
    }

    public function rejectsNonResource(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Expected a stream resource, got string.');

        /** @phpstan-ignore argument.type */
        new Stream('php://memory');
    }

    /**
     * @return array{Stream, Stream}
     */
    private static function pair(): array
    {
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        \assert(\is_array($pair) && isset($pair[0], $pair[1]));

        return [new Stream($pair[0]), new Stream($pair[1])];
    }

    /**
     * @return resource
     */
    private static function open(string $filename, string $mode): mixed
    {
        $handle = fopen($filename, $mode);
        \assert(\is_resource($handle));

        return $handle;
    }
}

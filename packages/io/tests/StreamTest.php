<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Task;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

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

    public function readThrowsStreamClosedAfterClose(): void
    {
        [$left] = self::pair();
        $left->close();
        Expect::exception(StreamClosed::class)->withMessage('The stream is closed.');

        $left->read(1);
    }

    public function closeEndsEveryWaitingWrite(): void
    {
        [$left, $right] = self::pair();

        $errors = timeout(sec(1), static fn() => group(static function (Group $group) use ($left) {
            /** @var list<Task<string>> $writes */
            $writes = [];

            // the first write waits for the peer holding the lock, the second one waits for the lock
            foreach (['a', 'b'] as $byte) {
                $writes[] = $group->spawn(static function () use ($left, $byte) {
                    try {
                        $left->write(str_repeat($byte, 10_000_000));
                    } catch (StreamClosed $error) {
                        return $error->getMessage();
                    }

                    return 'written';
                });
                delay(ms(1));
            }

            $left->close();

            return array_map(static fn(Task $write): string => $write->await(), $writes);
        }));

        Assert::same($errors, ['The stream was closed while writing.', 'The stream was closed while writing.']);
        unset($right);
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

    public function secondReaderIsAnErrorEvenIfBytesHaveArrivedForTheFirst(): void
    {
        [$left, $right] = self::pair();
        Expect::exception(\LogicException::class)->withMessage('Another fiber is reading from the stream.');

        // without the error, the first reader would wait forever for the bytes the second has taken
        timeout(sec(1), static fn() => group(static function (Group $group) use ($left, $right) {
            $group->spawn(static fn() => $right->read(100));
            delay(ms(1));
            $left->write('for the first');
            $right->read(100);
        }));
    }

    public function readsThatNeverWaitStillLetOtherTasksRun(): void
    {
        $stream = new Stream(self::memory(str_repeat('x', 1000)));

        $readsWhenTicked = group(static function (Group $group) use ($stream) {
            $done = new \stdClass();
            $done->reads = 0;
            $ticked = $group->spawn(static fn() => $done->reads);

            for (; $done->reads < 1000; ++$done->reads) {
                $stream->read(1);
            }

            return $ticked->await();
        });

        Assert::true($readsWhenTicked < 1000);
    }

    public function writesThatNeverWaitStillLetOtherTasksRun(): void
    {
        $stream = new Stream(self::open('php://memory', 'w'));

        $writesWhenTicked = group(static function (Group $group) use ($stream) {
            $done = new \stdClass();
            $done->writes = 0;
            $ticked = $group->spawn(static fn() => $done->writes);

            for (; $done->writes < 1000; ++$done->writes) {
                $stream->write('x');
            }

            return $ticked->await();
        });

        Assert::true($writesWhenTicked < 1000);
    }

    public function readDoesNotAllocateTheWholeMaxUpFront(): void
    {
        [$left, $right] = self::pair();
        $left->write('x');
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $right->read(512 * 1_048_576);

        Assert::true(memory_get_peak_usage() - $before < 4 * 1_048_576);
    }

    public function writeCancelledAfterAPartClosesTheStream(): void
    {
        [$left, $right] = self::pair();

        timeoutOr(ms(10), static fn() => $left->write(str_repeat('x', 10_000_000)));

        Assert::true($left->isClosed);
        Assert::false($right->isClosed);
    }

    public function writeCancelledBeforeWritingAnythingLeavesTheStreamOpen(): void
    {
        [$left, $right] = self::pair();

        group(static function (Group $group) use ($left, $right) {
            $group->spawn(static fn() => $left->write(str_repeat('a', 10_000_000)));
            delay(ms(10));

            // waits for the write above
            timeoutOr(ms(10), static fn() => $left->write('b'));

            Assert::false($left->isClosed);
            $buffer = new Buffer();
            $buffer->fill($right, 10_000_000);
        });

        $left->write('c');
        Assert::same($right->read(1), 'c');
    }

    public function writeDoesNotSlipIntoRoomFreedForAWaitingWrite(): void
    {
        [$left, $right] = self::pair();
        $a = str_repeat('a', 4_000_000);

        $received = group(static function (Group $group) use ($left, $right, $a) {
            $group->spawn(static fn() => $left->write($a));
            delay(ms(1));
            $buffer = new Buffer();
            // frees room while the first write still waits, then a second write starts before it resumes
            $buffer->fill($right, 1000);
            $group->spawn(static fn() => $left->write('BBBB'));
            delay(ms(1));
            $buffer->fill($right, 4_000_004);

            return $buffer->drain();
        });

        Assert::true($received === $a . 'BBBB');
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

    public function rejectsResourceThatIsNotAStream(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Expected a stream resource');

        new Stream(stream_context_create());
    }

    public function rejectsClosedResource(): void
    {
        $handle = self::open('php://memory', 'r');
        fclose($handle);
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Expected a stream resource');

        new Stream($handle);
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
    private static function memory(string $bytes): mixed
    {
        $handle = self::open('php://memory', 'w+');
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
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

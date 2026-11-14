<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

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

    public function throwsOnFailedWrite(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'thesis-io-');
        \assert(\is_string($file));
        $this->file = $file;
        $stream = new Stream(self::open($file, 'r'));
        Expect::exception(WriteFailed::class);

        $stream->write('abc');
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
     * @return resource
     */
    private static function open(string $filename, string $mode): mixed
    {
        $handle = fopen($filename, $mode);
        \assert(\is_resource($handle));

        return $handle;
    }
}

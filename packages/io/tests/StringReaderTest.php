<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(StringReader::class)]
final class StringReaderTest
{
    public function readsBytesInChunksAndKeepsTheTotalSize(): void
    {
        $reader = new StringReader("a\0éz");

        Assert::same($reader->read(3), "a\0\xC3");
        Assert::same($reader->size, 5);
        Assert::same($reader->read(PHP_INT_MAX), "\xA9z");
        Assert::null($reader->read(1));
    }

    public function emptyStringIsAlreadyAtEnd(): void
    {
        $reader = new StringReader('');

        Assert::same($reader->size, 0);
        Assert::null($reader->read(1));
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;

/**
 * Every byte until the end of the stream.
 */
function readAll(Reader $reader): string
{
    $bytes = '';

    while (true) {
        try {
            $bytes .= $reader->read(65_536);
        } catch (UnexpectedEof) {
            return $bytes;
        }
    }
}

/**
 * What $fn throws, for a test that goes on after the failure.
 * When the exception is the only outcome, use Expect::exception() instead.
 *
 * @param callable(): mixed $fn
 */
function thrown(callable $fn): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $exception) {
        return $exception;
    }

    Assert::fail('Nothing was thrown.');
}

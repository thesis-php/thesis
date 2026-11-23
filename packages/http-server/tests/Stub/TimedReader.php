<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Stub;

use Thesis\Io\Reader;
use Thesis\Io\UnexpectedEof;
use Time\Duration;
use function Thesis\Async\delay;

final class TimedReader implements Reader
{
    /**
     * @param list<array{?Duration, non-empty-string}> $parts delay before each part and the bytes to deliver
     */
    public function __construct(
        private array $parts,
    ) {}

    public function read(int $max): string
    {
        [$wait, $bytes] = $this->parts[0] ?? throw new UnexpectedEof();
        if ($wait !== null) {
            delay($wait);
        }

        if (\strlen($bytes) <= $max) {
            array_shift($this->parts);

            return $bytes;
        }

        $remaining = substr($bytes, $max);
        \assert($remaining !== '');
        $this->parts[0] = [null, $remaining];

        return substr($bytes, 0, $max);
    }
}

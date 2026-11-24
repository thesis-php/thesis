<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Stub;

use Thesis\Io\Reader;
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

    public function read(int $max): ?string
    {
        if ($this->parts === []) {
            return null;
        }

        [$wait, $bytes] = $this->parts[0];
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

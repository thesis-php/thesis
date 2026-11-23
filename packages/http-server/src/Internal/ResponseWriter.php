<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

use Thesis\Io\WriteFailed;
use Thesis\Io\Writer;
use Time\Duration;
use function Thesis\Async\timeoutOr;

/**
 * @internal
 */
final readonly class ResponseWriter implements Writer
{
    public function __construct(
        private Writer $output,
        private Duration $timeout,
    ) {}

    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $written = timeoutOr($this->timeout, function () use ($bytes) {
            $this->output->write($bytes);

            return true;
        });

        if ($written === null) {
            throw new WriteFailed('The response write did not complete in time.');
        }
    }
}

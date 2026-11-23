<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

use Thesis\Io\WriteFailed;
use Thesis\Io\Writer;
use Thesis\Net\Socket;
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
        private ?SocketDeadlines $deadlines,
    ) {}

    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        if ($this->deadlines !== null && $this->output instanceof Socket) {
            $this->deadlines->write($this->output, $bytes);

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

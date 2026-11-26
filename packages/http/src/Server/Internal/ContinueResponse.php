<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal;

use Thesis\Io\IoException;
use Thesis\Io\ReadFailed;
use Thesis\Io\Writer;

/**
 * @internal
 */
final class ContinueResponse
{
    public private(set) bool $isPending = true;

    public private(set) ?IoException $failure = null;

    private bool $canSend = true;

    public function __construct(
        private readonly Writer $output,
    ) {}

    public function send(): void
    {
        if (!$this->isPending) {
            return;
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if (!$this->canSend) {
            throw new ReadFailed('The final response has started without accepting the request body.');
        }

        try {
            $this->output->write("HTTP/1.1 100 Continue\r\n\r\n");
        } catch (IoException $error) {
            $this->failure = $error;

            throw $error;
        }

        $this->isPending = false;
    }

    public function commit(): void
    {
        $this->canSend = false;
    }
}

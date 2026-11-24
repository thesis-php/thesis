<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\Io\ReadFailed;
use Thesis\Io\StreamClosed;

/**
 * @internal
 */
final class DecodedBody implements Body
{
    public ?int $size {
        get => null;
    }

    public bool $isBuffered {
        get => $this->body->isBuffered;
    }

    public bool $mayHaveTrailers {
        get => $this->body->mayHaveTrailers;
    }

    private ?\InflateContext $context = null;

    private bool $memberEnded = false;

    private string $input = '';

    private string $output = '';

    private int $offset = 0;

    private bool $closed = false;

    private ?ReadFailed $failure = null;

    /**
     * @param \ZLIB_ENCODING_GZIP|\ZLIB_ENCODING_DEFLATE $encoding
     */
    public function __construct(
        private readonly Body $body,
        private readonly int $encoding,
    ) {}

    private function __clone() {}

    public function read(int $max): ?string
    {
        if ($this->closed) {
            throw new StreamClosed('The decoded response body is closed.');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        while ($this->output === '') {
            if ($this->input === '') {
                // Limit the output zlib can allocate in a single call even for highly compressed input.
                $bytes = $this->body->read(1024);
                if ($bytes === null) {
                    if (!$this->memberEnded) {
                        $this->fail('The compressed response body ended early.');
                    }

                    return null;
                }

                $this->input = $bytes;
            }

            if ($this->context === null) {
                if ($this->memberEnded && $this->encoding !== ZLIB_ENCODING_GZIP) {
                    $this->fail('Unexpected bytes follow the deflate stream.');
                }

                $context = inflate_init($this->encoding);
                \assert($context !== false);
                $this->context = $context;
                $this->memberEnded = false;
            }

            $before = inflate_get_read_len($this->context);
            error_clear_last();
            $output = @inflate_add($this->context, $this->input);
            if ($output === false) {
                $this->fail(error_get_last()['message'] ?? 'Invalid compressed response body.');
            }

            if (inflate_get_status($this->context) === ZLIB_STREAM_END) {
                // A gzip representation may concatenate members, including inside a single read.
                $this->input = substr($this->input, inflate_get_read_len($this->context) - $before);
                $this->context = null;
                $this->memberEnded = true;
            } else {
                $this->input = '';
            }
            $this->output = $output;
        }

        $bytes = substr($this->output, $this->offset, $max);
        $this->offset += \strlen($bytes);
        if ($this->offset === \strlen($this->output)) {
            $this->output = '';
            $this->offset = 0;
        }

        \assert($bytes !== '');

        return $bytes;
    }

    public function discard(): Headers
    {
        while ($this->read(8192) !== null);

        return $this->body->discard();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->input = $this->output = '';
        $this->context = null;
        $this->body->close();
    }

    private function fail(string $message): never
    {
        $this->failure = new ReadFailed($message);
        $this->body->close();

        throw $this->failure;
    }
}

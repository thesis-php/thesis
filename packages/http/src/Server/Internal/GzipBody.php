<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal;

use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\Http\StreamingBody;
use Thesis\Http\StringBody;
use Thesis\Io\ReadFailed;
use Thesis\Io\StreamClosed;

/**
 * @internal
 */
final class GzipBody extends StreamingBody
{
    private const int READ_SIZE = 8192;

    #[\Override]
    public ?int $size {
        get => null;
    }

    #[\Override]
    public bool $isBuffered {
        get => $this->body->isBuffered;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => $this->body->mayHaveTrailers;
    }

    private ?\DeflateContext $context = null;

    private bool $finished = false;

    private string $output = '';

    private int $offset = 0;

    private bool $closed = false;

    public function __construct(
        private readonly Body $body,
    ) {}

    private function __clone() {}

    protected function readPayload(int $max): ?string
    {
        while ($this->offset === \strlen($this->output)) {
            if ($this->closed) {
                throw new StreamClosed('The compressed response body is closed.');
            }

            if ($this->finished) {
                return null;
            }

            $bytes = $this->body->read(self::READ_SIZE);

            if ($this->closed) { // @phpstan-ignore if.alwaysFalse (close() can run while the source read waits)
                throw new StreamClosed('The compressed response body is closed.');
            }

            if ($this->context === null) {
                $context = deflate_init(ZLIB_ENCODING_GZIP);
                \assert($context !== false);
                $this->context = $context;
            }

            $this->finished = $bytes === null
                || ($this->body instanceof StringBody && \strlen($bytes) < self::READ_SIZE);
            $output = deflate_add($this->context, $bytes ?? '', $this->finished ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

            if ($output === false) {
                $this->close();

                throw new ReadFailed('Failed to compress the response body.');
            }

            if ($this->finished) {
                $this->context = null;
            }

            $this->output = $output;
            $this->offset = 0;
        }

        $bytes = substr($this->output, $this->offset, $max);
        $this->offset += \strlen($bytes);

        \assert($bytes !== '');

        return $bytes;
    }

    public static function encodedHeaders(Headers $headers): Headers
    {
        $headers = $headers->without('content-digest', 'repr-digest');
        $etag = $headers->get('etag');

        if ($etag !== null && str_starts_with($etag, '"')) {
            return $headers->with('etag', 'W/' . $etag);
        }

        return $headers;
    }

    public function trailers(): Headers
    {
        return self::encodedHeaders($this->body->trailers());
    }

    public function close(): void
    {
        $this->closed = true;
        $this->output = '';
        $this->offset = 0;
        $this->context = null;
        $this->body->close();
    }
}

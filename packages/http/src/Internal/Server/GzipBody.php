<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\Http\StreamingBody;
use Thesis\Io\ReadFailed;
use Thesis\Io\StreamClosed;

/**
 * @internal
 */
final class GzipBody extends StreamingBody
{
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

    private ?\DeflateContext $context;

    private string $output = '';

    private int $offset = 0;

    private bool $closed = false;

    public function __construct(
        private readonly Body $body,
    ) {
        $context = deflate_init(ZLIB_ENCODING_GZIP);
        \assert($context !== false);

        $this->context = $context;
    }

    private function __clone() {}

    protected function readPayload(int $max): ?string
    {
        while ($this->offset === \strlen($this->output)) {
            if ($this->context === null) {
                if ($this->closed) {
                    throw new StreamClosed('The compressed response body is closed.');
                }

                return null;
            }

            $bytes = $this->body->read(8192);

            if ($this->closed) {
                throw new StreamClosed('The compressed response body is closed.');
            }

            $output = deflate_add($this->context, $bytes ?? '', $bytes === null ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

            if ($output === false) {
                $this->close();

                throw new ReadFailed('Failed to compress the response body.');
            }

            if ($bytes === null) {
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

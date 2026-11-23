<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Thesis\Http\Body;
use Thesis\Http\Http1\ChunkedReader;
use Thesis\Http\Http1\FixedLengthReader;
use Thesis\Http\Http1\Framing;
use Thesis\Http\Http1\MalformedMessage;
use Thesis\Http\Http1\RequestHead;
use Thesis\Http\Http1\ResponseHead;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\HttpClient\Internal\ConnectionPool;
use Thesis\HttpClient\Internal\ResponseBody;
use Thesis\HttpClient\Internal\SizedResponseBody;
use Thesis\HttpClient\Internal\UntilCloseReader;
use Thesis\Io\Buffer;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use Thesis\Net\ClientTls;
use Thesis\Net\Socket;
use function Thesis\Http\Http1\encodeChunk;
use function Thesis\Http\Http1\encodeLastChunk;
use function Thesis\Net\connect;

/**
 * @api
 */
final readonly class Client
{
    private ConnectionPool $pool;

    public function __construct(
        private ClientTls $tls = new ClientTls(),
    ) {
        $this->pool = new ConnectionPool();
    }

    /**
     * Returns after reading the final response head. Close the body to abandon the download.
     */
    public function send(Request $request): Response
    {
        $scheme = strtolower($request->uri->getScheme() ?? '');
        if (!\in_array($scheme, ['http', 'https'], strict: true) || $request->method === Method::CONNECT) {
            throw new \ValueError('The client requires an HTTP or HTTPS request without tunneling.');
        }

        $head = RequestHead::of($request);
        $host = $request->uri->getRawHost();
        \assert($host !== null && $host !== '');
        $port = $request->uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        \assert($port >= 0 && $port <= 65535);
        $origin = $scheme . '://' . strtolower($host) . ':' . $port;
        $socket = $this->pool->take($origin) ?? connect($host, $port, $scheme === 'https' ? $this->tls : null);

        try {
            $socket->write($head->toString());
            self::writeBody($socket, $request, $head);

            return $this->readResponse($socket, $head->method, $origin);
        } catch (\Throwable $error) {
            $socket->close();

            throw $error;
        }
    }

    /**
     * Closes the response body when the callback returns or throws.
     *
     * @template T
     * @param callable(Response): T $consume
     * @return T
     * @throws \Throwable if the callback throws
     */
    public function withResponse(Request $request, callable $consume): mixed
    {
        $response = $this->send($request);

        try {
            return $consume($response);
        } finally {
            $response->body->close();
        }
    }

    private static function writeBody(Socket $socket, Request $request, RequestHead $head): void
    {
        $remaining = $head->length;

        while ($head->framing !== Framing::None && ($remaining === null || $remaining > 0)) {
            $bytes = $request->body->read(min(8192, $remaining ?? 8192));
            if ($bytes === null) {
                if ($remaining !== null) {
                    throw new ReadFailed('The request body ended before its declared length.');
                }

                break;
            }

            $socket->write($head->framing === Framing::Chunked ? encodeChunk($bytes) : $bytes);
            if ($remaining !== null) {
                $remaining -= \strlen($bytes);
            }
        }

        if ($head->framing === Framing::Chunked) {
            $trailers = $request->body->trailers ?? throw new \ValueError('Missing request trailers.');
            $socket->write(encodeLastChunk($trailers));
        }
    }

    /**
     * @param non-empty-string $method
     */
    private function readResponse(Socket $socket, string $method, string $origin): Response
    {
        $buffer = new Buffer();
        do {
            try {
                $buffer->fillUntil($socket, "\r\n\r\n");
            } catch (UnexpectedEof) {
                throw new MalformedMessage('The connection ended in a response head.');
            } catch (\OverflowException) {
                throw new MalformedMessage('The response head exceeds 65536 bytes.');
            }

            $bytes = $buffer->readUntil("\r\n\r\n");
            \assert($bytes !== null);
            $head = ResponseHead::parse($bytes, $method);
            if ($head->status === 101) {
                throw new MalformedMessage('The server switched protocols without an upgrade request.');
            }
        } while ($head->status < 200);

        $reader = match ($head->framing) {
            Framing::None => new FixedLengthReader($buffer, $socket, 0),
            Framing::Length => new FixedLengthReader($buffer, $socket, $head->length ?? throw new \LogicException()),
            Framing::Chunked => new ChunkedReader($buffer, $socket),
            Framing::UntilClose => new UntilCloseReader($buffer, $socket),
        };

        $pool = \WeakReference::create($this->pool);
        $release = static function (Socket $socket) use ($pool, $origin, $buffer, $head) {
            $owner = $pool->get();
            if ($owner !== null && $head->isPersistent && $buffer->count() === 0) {
                $owner->release($origin, $socket);
            } else {
                $socket->close();
            }
        };
        $body = new ResponseBody($reader, $socket, $release);

        return new Response(
            status: $head->status,
            headers: $head->headers,
            body: match (true) {
                $reader instanceof FixedLengthReader => new SizedResponseBody($body, $reader->size),
                $reader instanceof ChunkedReader => new Body($body, static fn() => $reader->trailers),
                default => $body,
            },
        );
    }
}

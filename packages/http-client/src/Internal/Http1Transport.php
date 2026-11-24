<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Async\LocalKeyedSemaphore;
use Thesis\Async\Lock;
use Thesis\Http\Body;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\HttpProtocol\Http1\ChunkedReader;
use Thesis\HttpProtocol\Http1\FixedLengthReader;
use Thesis\HttpProtocol\Http1\Framing;
use Thesis\HttpProtocol\Http1\MalformedMessage;
use Thesis\HttpProtocol\Http1\RequestHead;
use Thesis\HttpProtocol\Http1\ResponseHead;
use Thesis\Io\Buffer;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use Thesis\Net\ClientTls;
use Thesis\Net\Socket;
use function Thesis\HttpProtocol\Http1\encodeChunk;
use function Thesis\HttpProtocol\Http1\encodeLastChunk;
use function Thesis\Net\connect;

/**
 * @internal
 */
final readonly class Http1Transport
{
    /**
     * @var \Closure(non-empty-string, int<0, 65535>, ?ClientTls): Socket
     */
    private \Closure $connect;

    private ConnectionPool $pool;

    private LocalKeyedSemaphore $slots;

    /**
     * @param positive-int $maxConnectionsPerOrigin maximum simultaneous HTTP exchanges for a scheme, host and port
     * @param non-negative-int $maxIdleConnections maximum sockets retained by this client between requests
     * @param null|callable(non-empty-string, int<0, 65535>, ?ClientTls): Socket $connector
     * @param positive-int $maxResponseHeadBytes
     */
    public function __construct(
        private ClientTls $tls = new ClientTls(),
        int $maxConnectionsPerOrigin = 10,
        int $maxIdleConnections = 32,
        ?callable $connector = null,
        private int $maxResponseHeadBytes = 64 * 1024,
    ) {
        $this->connect = $connector === null ? connect(...) : $connector(...);
        $this->slots = new LocalKeyedSemaphore($maxConnectionsPerOrigin);
        $this->pool = new ConnectionPool($maxIdleConnections);
    }

    /**
     * Returns after reading the final response head. Close the body to abandon the download.
     */
    public function send(Request $request): Response
    {
        $slot = null;
        $socket = null;
        $body = $request->takeBody();

        try {
            try {
                $scheme = strtolower($request->uri->getScheme() ?? '');
                if (!\in_array($scheme, ['http', 'https'], strict: true) || $request->method === Method::CONNECT) {
                    throw new \ValueError('The client requires an HTTP or HTTPS request without tunneling.');
                }

                if ($request->uri->getRawUserInfo() !== null) {
                    throw new \ValueError('Supply credentials in request headers, not in the URI.');
                }

                $head = RequestHead::of($request, body: $body);
                $host = $request->uri->getRawHost();
                \assert($host !== null && $host !== '');
                $port = $request->uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
                \assert($port >= 0 && $port <= 65535);
                $origin = $scheme . '://' . strtolower($host) . ':' . $port;
                $slot = $this->slots->acquire($origin);

                $tls = $scheme === 'https' ? $this->tls : null;
                $socket = $this->pool->take($origin) ?? ($this->connect)($host, $port, $tls);
                $socket->write($head->toString());
                self::writeBody($socket, $body, $head);
            } finally {
                $body->close();
            }

            return $this->readResponse($socket, $head->method, $origin, $slot);
        } catch (\Throwable $error) {
            $socket?->close();
            $slot?->release();

            throw $error;
        }
    }

    private static function writeBody(Socket $socket, Body $body, RequestHead $head): void
    {
        $remaining = $head->length;

        while ($head->framing !== Framing::None && ($remaining === null || $remaining > 0)) {
            $bytes = $body->read(min(8192, $remaining ?? 8192));
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
            $socket->write(encodeLastChunk($body->trailers()));
        }
    }

    /**
     * @param non-empty-string $method
     */
    private function readResponse(Socket $socket, string $method, string $origin, Lock $slot): Response
    {
        $buffer = new Buffer();
        $head = null;
        $remaining = $this->maxResponseHeadBytes;
        do {
            if ($remaining <= 0) {
                throw new MalformedMessage("The response heads exceed {$this->maxResponseHeadBytes} bytes.");
            }

            try {
                $buffer->fillUntil($socket, "\r\n\r\n", $remaining);
            } catch (UnexpectedEof) {
                if ($head === null && $buffer->count() === 0) {
                    throw new ReadFailed('The peer closed the connection before sending a response.');
                }

                throw new MalformedMessage('The connection ended in a response head.');
            } catch (\OverflowException) {
                throw new MalformedMessage("The response heads exceed {$this->maxResponseHeadBytes} bytes.");
            }

            $bytes = $buffer->readUntil("\r\n\r\n");
            \assert($bytes !== null);
            $remaining -= \strlen($bytes) + 4;
            $head = ResponseHead::parse($bytes, $method);
            if ($head->status === 101) {
                throw new MalformedMessage('The server switched protocols without an upgrade request.');
            }
        } while ($head->status < 200);

        $pool = \WeakReference::create($this->pool);
        $release = static function (Socket $socket, bool $complete) use ($pool, $origin, $buffer, $head, $slot) {
            $owner = $pool->get();
            if ($complete && $owner !== null && $head->isPersistent && $buffer->count() === 0) {
                $owner->release($origin, $socket);
            } else {
                $socket->close();
            }

            $slot->release();
        };

        if ($head->length === 0) {
            $release($socket, true);

            return new Response(status: $head->status, headers: $head->headers);
        }

        $reader = match ($head->framing) {
            Framing::None, Framing::Length => new FixedLengthReader(
                buffer: $buffer,
                reader: $socket,
                size: $head->length ?? throw new \LogicException(),
            ),
            Framing::Chunked => new ChunkedReader($buffer, $socket),
            Framing::UntilClose => new UntilCloseReader($buffer, $socket),
        };

        return new Response(
            status: $head->status,
            headers: $head->headers,
            body: new ResponseBody($reader, $socket, $release),
        );
    }
}

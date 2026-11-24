<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Thesis\Http\Headers;
use Thesis\Http\Http1\RequestHead;
use Thesis\Http\Http1\ResponseHead;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Io\Buffer;
use Thesis\Io\UnexpectedEof;
use Thesis\Net\ClientTls;
use Thesis\Net\ConnectFailed;
use Thesis\Net\Socket;
use Uri\Rfc3986\Uri;
use function Thesis\Net\connect;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * Opens a tunnel through a plaintext HTTP/1 CONNECT proxy.
 *
 * @api
 */
final readonly class HttpProxy
{
    /**
     * @param non-empty-string $host the proxy host name or IP address
     * @param int<0, 65535> $port the proxy port
     * @param Headers $headers fields sent only in the CONNECT request, such as Proxy-Authorization
     */
    public function __construct(
        private string $host,
        private int $port,
        private Headers $headers = EMPTY_HEADERS,
    ) {}

    /**
     * @param non-empty-string $host the origin host name or IP address
     * @param int<0, 65535> $port the origin port
     * @throws ConnectFailed if the proxy refuses the tunnel or returns an incomplete response
     */
    public function __invoke(string $host, int $port, ?ClientTls $tls): Socket
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = "[{$host}]";
        }

        $uri = new Uri('http://localhost')->withHost($host)->withPort($port);
        $connect = new Request(Method::CONNECT, $uri, $this->headers);
        $request = RequestHead::of($connect, body: $connect->takeBody());
        $socket = connect($this->host, $this->port);

        try {
            $socket->write($request->toString());
            $buffer = new Buffer();
            $remaining = 64 * 1024;
            do {
                if ($remaining <= 0) {
                    throw new ConnectFailed('The proxy response heads exceed 65536 bytes.');
                }

                $buffer->fillUntil($socket, "\r\n\r\n", $remaining);
                $bytes = $buffer->readUntil("\r\n\r\n");
                \assert($bytes !== null);
                $remaining -= \strlen($bytes) + 4;
                $head = ResponseHead::parse($bytes, Method::CONNECT);
            } while ($head->status < 200 && $head->status !== 101);

            if ($head->status < 200 || $head->status >= 300) {
                throw new ConnectFailed("The proxy refused CONNECT with HTTP status {$head->status}.");
            }

            if ($buffer->count() !== 0) {
                throw new ConnectFailed('The proxy sent bytes before the tunneled request.');
            }

            if ($tls !== null) {
                $peer = trim($host, '[]');
                \assert($peer !== '');
                $socket->enableTls($tls->peerName === null ? clone($tls, ['peerName' => $peer]) : $tls);
            }

            return $socket;
        } catch (\OverflowException|UnexpectedEof $error) {
            $socket->close();

            throw new ConnectFailed('The proxy response head is incomplete or exceeds 65536 bytes.', previous: $error);
        } catch (\Throwable $error) {
            $socket->close();

            throw $error;
        }
    }
}

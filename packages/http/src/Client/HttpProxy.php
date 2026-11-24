<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Thesis\Http\Headers;
use Thesis\Http\Internal\Http1\MalformedMessage;
use Thesis\Http\Internal\Http1\RequestHead;
use Thesis\Http\Internal\Http1\ResponseHead;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Io\Buffer;
use Thesis\Io\ReadFailed;
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
     * @throws ConnectFailed if the proxy refuses the tunnel or returns an invalid or incomplete response
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

            try {
                $head = ResponseHead::read($buffer, $socket, Method::CONNECT, 64 * 1024);
            } catch (MalformedMessage|ReadFailed $error) {
                throw new ConnectFailed('The proxy response could not be read: ' . $error->getMessage(), previous: $error);
            }

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
        } catch (\Throwable $error) {
            $socket->close();

            throw $error;
        }
    }
}

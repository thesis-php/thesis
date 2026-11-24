<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Http\Client;
use Thesis\Http\Headers;
use Thesis\Io\Buffer;
use Thesis\Net\Certificate;
use Thesis\Net\ClientTls;
use Thesis\Net\ConnectFailed;
use Thesis\Net\InetAddress;
use Thesis\Net\ServerTls;
use Thesis\Net\TlsFailed;
use function Thesis\Async\group;
use function Thesis\Net\listen;

#[Test]
#[Covers(HttpProxy::class)]
final class HttpProxyTest
{
    public function tunnelsAnOriginRequestAndKeepsProxyCredentialsOnConnect(): void
    {
        group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $address = $listener->address;
            \assert($address instanceof InetAddress);
            $group->spawn(static function () use ($listener) {
                $peer = $listener->accept() ?? throw new \LogicException();
                $buffer = new Buffer();
                $buffer->fillUntil($peer, "\r\n\r\n");
                Assert
                    ::string($buffer->drain())
                    ->startsWith("CONNECT [::1]:8080 HTTP/1.1\r\n")
                    ->contains('Basic YTpi')
                ;
                $peer->write("HTTP/1.1 103 Early Hints\r\n\r\nHTTP/1.1 200 Connection Established\r\n\r\n");
                $buffer->fillUntil($peer, "\r\n\r\n");
                Assert::string($buffer->drain())->startsWith("GET /test HTTP/1.1\r\n")->notContains('Basic YTpi');
                $peer->write("HTTP/1.1 204 No Content\r\n\r\n");
                $peer->close();
                $listener->close();
            });

            $headers = new Headers(['Proxy-Authorization' => 'Basic YTpi']);
            $client = new Client(connector: new HttpProxy($address->ip, $address->port, $headers));
            Assert::same($client->send('http://[::1]:8080/test')->status, 204);
        });
    }

    public function verifiesTheTlsCertificateAgainstTheTunneledOrigin(): void
    {
        self::sendThroughTlsTunnel('origin.test');
    }

    public function rejectsACertificateForAnotherOrigin(): void
    {
        Expect::exception(TlsFailed::class)->withMessageContaining('wrong.test');
        self::sendThroughTlsTunnel('wrong.test');
    }

    private static function sendThroughTlsTunnel(string $host): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        $request = openssl_csr_new(['commonName' => 'origin.test'], $key);
        \assert($request instanceof \OpenSSLCertificateSigningRequest && $key instanceof \OpenSSLAsymmetricKey);
        $certificate = openssl_csr_sign($request, null, $key, days: 1);
        \assert($certificate instanceof \OpenSSLCertificate);
        $certFile = tempnam(sys_get_temp_dir(), 'proxy-cert-');
        $keyFile = tempnam(sys_get_temp_dir(), 'proxy-key-');
        \assert($certFile !== false && $keyFile !== false);

        try {
            openssl_x509_export_to_file($certificate, $certFile);
            openssl_pkey_export_to_file($key, $keyFile);
            group(static function (Group $group) use ($certFile, $keyFile, $host) {
                $listener = listen('127.0.0.1', 0);
                $address = $listener->address;
                \assert($address instanceof InetAddress);
                $group->spawn(static function () use ($listener, $certFile, $keyFile) {
                    $peer = $listener->accept() ?? throw new \LogicException();
                    new Buffer()->fillUntil($peer, "\r\n\r\n");
                    $peer->write("HTTP/1.1 200 OK\r\n\r\n");
                    $peer->enableTls(new ServerTls(new Certificate($certFile, $keyFile)));
                    new Buffer()->fillUntil($peer, "\r\n\r\n");
                    $peer->write("HTTP/1.1 204 No Content\r\n\r\n");
                    $peer->close();
                    $listener->close();
                });

                $client = new Client(
                    tls: new ClientTls(caFile: $certFile),
                    connector: new HttpProxy($address->ip, $address->port),
                );
                Assert::same($client->send("https://{$host}/")->status, 204);
            });
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    /**
     * @param non-empty-string $message
     */
    #[DataSet(['HTTP/1.1 407 Proxy Authentication Required', 'The proxy refused CONNECT with HTTP status 407.'], 'refused')]
    #[DataSet(['invalid', 'The proxy response could not be read'], 'malformed')]
    public function closesARejectedTunnel(string $statusLine, string $message): void
    {
        group(static function (Group $group) use ($statusLine, $message) {
            $listener = listen('127.0.0.1', 0);
            $address = $listener->address;
            \assert($address instanceof InetAddress);
            $server = $group->spawn(static function () use ($listener, $statusLine) {
                $peer = $listener->accept() ?? throw new \LogicException();
                new Buffer()->fillUntil($peer, "\r\n\r\n");
                $peer->write("{$statusLine}\r\n\r\n");
                Assert::null($peer->read(1));
                $peer->close();
                $listener->close();
            });

            $proxy = new HttpProxy($address->ip, $address->port);
            Expect::exception(ConnectFailed::class)->withMessageContaining($message);

            try {
                $proxy('origin.test', 443, null);
            } finally {
                $server->await();
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Net\InetAddress;

#[Test]
#[Covers(Config::class)]
final class ConfigTest
{
    public function expandsAddressesUsingTransportDefaults(): void
    {
        $address = new InetAddress('127.0.0.1', 0);

        $tcp = new Config($address);
        $unix = new Config('/test.sock');

        Assert::equals($tcp->endpoint, new TcpEndpoint($address, backlog: 511, reusePort: false, noDelay: true));
        Assert::equals($unix->endpoint, new UnixEndpoint('/test.sock', backlog: 511));
    }

    public function expandsPortToAnIpv4LoopbackEndpoint(): void
    {
        $config = new Config(8080);

        Assert::equals($config->endpoint, new TcpEndpoint(new InetAddress('127.0.0.1', 8080)));
    }

    public function rejectsRelativeSocketPathStrings(): void
    {
        Expect::exception(\ValueError::class);

        new Config('app.sock');
    }

    public function keepsExplicitEndpointsAndHttpLimits(): void
    {
        $tcp = new TcpEndpoint(new InetAddress('127.0.0.1', 0), backlog: 32, reusePort: true, noDelay: false);
        $unix = new UnixEndpoint('test.sock', backlog: 16);

        $tcpConfig = new Config($tcp, maxConnections: 8);
        $unixConfig = new Config($unix, maxRequestsPerConnection: 2);

        Assert::same($tcpConfig->endpoint, $tcp);
        Assert::same($tcpConfig->maxConnections, 8);
        Assert::same($unixConfig->endpoint, $unix);
        Assert::same($unixConfig->maxRequestsPerConnection, 2);
    }
}

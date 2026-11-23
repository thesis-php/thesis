<?php

declare(strict_types=1);

namespace Thesis\Net;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
final class InetAddressTest
{
    /**
     * @param non-empty-string $ip
     * @param int<0, 65535> $port
     */
    #[DataSet(['127.0.0.1:0', '127.0.0.1', 0], 'IPv4 with an OS-assigned port')]
    #[DataSet(['[::1]:65535', '::1', 65535], 'IPv6 with the highest port')]
    public function parsesAddressWithPort(string $address, string $ip, int $port): void
    {
        Assert::equals(InetAddress::fromString($address), new InetAddress($ip, $port));
    }

    #[DataSet(['127.0.0.1'], 'missing port')]
    #[DataSet(['127.0.0.1:65536'], 'port out of range')]
    #[DataSet(['::1:8080'], 'IPv6 without brackets')]
    #[DataSet(['999.0.0.1:8080'], 'invalid IP address')]
    #[DataSet(['localhost:8080'], 'hostname')]
    public function rejectsInvalidAddressStrings(string $address): void
    {
        Expect::exception(\ValueError::class);

        InetAddress::fromString($address);
    }
}

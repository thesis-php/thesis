<?php

declare(strict_types=1);

namespace Thesis\Net;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use function Thesis\Async\delay;
use function Thesis\Async\group;

#[Test]
final class DnsResolverTest
{
    public function resolvesBothFamiliesIpv6First(): void
    {
        $zone = new Zone(a: ['10.0.0.1', '10.0.0.2'], aaaa: ['fd00::1']);

        [$addresses, $queries] = self::resolve(['db.test' => $zone], 'DB.test');

        Assert::same($addresses, ['fd00::1', '10.0.0.1', '10.0.0.2']);
        Assert::same($queries, ['udp db.test AAAA', 'udp db.test A']);
    }

    public function takesTheAddressesBehindACname(): void
    {
        $zone = new Zone(a: ['10.0.0.1'], cname: 'web.test');

        [$addresses] = self::resolve(['www.test' => $zone], 'www.test');

        Assert::same($addresses, ['10.0.0.1']);
    }

    public function asksForAnInternationalizedNameInItsAsciiForm(): void
    {
        [$addresses, $queries] = self::resolve(['xn--e1afmkfd.test' => self::a('10.0.0.1')], 'Пример.test');

        Assert::same($addresses, ['10.0.0.1']);
        Assert::same($queries, ['udp xn--e1afmkfd.test AAAA', 'udp xn--e1afmkfd.test A']);
    }

    public function rejectsAnInternationalizedNameWithATooLongLabel(): void
    {
        $host = str_repeat('я', 70) . '.test';
        Expect::exception(DnsFailed::class)->withMessage("\"{$host}\" is not a valid host name.");

        new DnsResolver([new InetAddress(ip: '127.0.0.1', port: 1)])->resolve($host);
    }

    public function failsForAMissingHost(): void
    {
        Expect::exception(DnsFailed::class)->withMessage('Host "missing.test" not found.');

        self::resolve([], 'missing.test');
    }

    public function failsForAHostWithoutAddresses(): void
    {
        Expect::exception(DnsFailed::class)->withMessage('Host "empty.test" not found.');

        self::resolve(['empty.test' => new Zone()], 'empty.test');
    }

    public function triesTheSearchDomainsFirstForAShortName(): void
    {
        [$addresses, $queries] = self::resolve(
            zone: ['db' => self::a('10.0.0.9'), 'db.svc.test' => self::a('10.0.0.1')],
            host: 'db',
            search: ['svc.test'],
        );

        Assert::same($addresses, ['10.0.0.1']);
        Assert::same($queries, ['udp db.svc.test AAAA', 'udp db.svc.test A']);
    }

    public function triesTheNameAsIsFirstWithEnoughDots(): void
    {
        [$addresses, $queries] = self::resolve(['db.test' => self::a('10.0.0.1')], 'db.test', search: ['svc.test']);

        Assert::same($addresses, ['10.0.0.1']);
        Assert::same($queries, ['udp db.test AAAA', 'udp db.test A']);
    }

    public function skipsTheSearchDomainsForAnAbsoluteName(): void
    {
        [, $queries] = self::resolve(['db' => self::a('10.0.0.1')], 'db.', search: ['svc.test']);

        Assert::same($queries, ['udp db AAAA', 'udp db A']);
    }

    public function retriesOverTcpIfTruncated(): void
    {
        $zone = new Zone(a: ['10.0.0.1'], truncated: true);

        [$addresses, $queries] = self::resolve(['big.test' => $zone], 'big.test');

        Assert::same($addresses, ['10.0.0.1']);
        Assert::same($queries, ['udp big.test AAAA', 'udp big.test A', 'tcp big.test AAAA', 'tcp big.test A']);
    }

    public function asksTheNextServerIfOneFailsOrIsSilent(): void
    {
        $addresses = group(static function (Group $group) {
            $failing = FakeDns::start($group, ['db.test' => new Zone(rcode: 2)]);
            $silent = FakeDns::start($group, ['db.test' => new Zone(silent: true)]);
            $working = FakeDns::start($group, ['db.test' => self::a('10.0.0.1')]);
            $resolver = new DnsResolver(
                nameservers: [$failing->address, $silent->address, $working->address],
                timeout: 0.05,
            );

            return $resolver->resolve('db.test');
        });

        Assert::same($addresses, ['10.0.0.1']);
    }

    public function reportsWhatTheServersSaid(): void
    {
        Expect
            ::exception(DnsFailed::class)
            ->withMessage('Failed to resolve "db.test": db.test: 127.0.0.1:%d: server failure')
        ;

        group(static function (Group $group) {
            $failing = FakeDns::start($group, ['db.test' => new Zone(rcode: 2)]);

            try {
                new DnsResolver([$failing->address], attempts: 1)->resolve('db.test');
            } catch (DnsFailed $exception) {
                // the port is random
                throw new DnsFailed(str_replace((string) $failing->address->port, '%d', $exception->getMessage()));
            }
        });
    }

    public function cachesForTheTtl(): void
    {
        $zone = new Zone(a: ['10.0.0.1'], ttl: 1);

        $queries = group(static function (Group $group) use ($zone) {
            $dns = FakeDns::start($group, ['db.test' => $zone]);
            $resolver = new DnsResolver([$dns->address]);

            $resolver->resolve('db.test');
            $resolver->resolve('db.test');
            Assert::same(\count($dns->queries), 2);
            delay(1.01);
            $resolver->resolve('db.test');

            return \count($dns->queries);
        });

        Assert::same($queries, 4);
    }

    public function doesNotCacheAZeroTtl(): void
    {
        $zone = new Zone(a: ['10.0.0.1'], ttl: 0);

        $queries = group(static function (Group $group) use ($zone) {
            $dns = FakeDns::start($group, ['db.test' => $zone]);
            $resolver = new DnsResolver([$dns->address]);
            $resolver->resolve('db.test');
            $resolver->resolve('db.test');

            return \count($dns->queries);
        });

        Assert::same($queries, 4);
    }

    public function answersFromHostsWithoutAsking(): void
    {
        [$addresses, $queries] = self::resolve([], 'Db.Local', hosts: ['db.local' => ['10.0.0.5']]);

        Assert::same($addresses, ['10.0.0.5']);
        Assert::same($queries, []);
    }

    public function answersLocalhostAndIpAddressesWithoutAsking(): void
    {
        $nowhere = new InetAddress(ip: '127.0.0.1', port: 1);
        $resolver = new DnsResolver([$nowhere]);

        Assert::same($resolver->resolve('localhost'), ['::1', '127.0.0.1']);
        Assert::same($resolver->resolve('app.localhost'), ['::1', '127.0.0.1']);
        Assert::same($resolver->resolve('10.1.2.3'), ['10.1.2.3']);
    }

    public function readsTheSystemConfiguration(): void
    {
        Assert::notSame(DnsResolver::system()->resolve('localhost'), []);
        Assert::same(DnsResolver::system(), DnsResolver::system());
    }

    /**
     * @param non-empty-string $ip
     */
    private static function a(string $ip): Zone
    {
        return new Zone(a: [$ip]);
    }

    /**
     * @param array<string, Zone> $zone
     * @param non-empty-string $host
     * @param list<non-empty-string> $search
     * @param array<non-empty-string, non-empty-list<non-empty-string>> $hosts
     * @return array{list<string>, list<string>} the addresses and the queries the server got
     */
    private static function resolve(array $zone, string $host, array $search = [], array $hosts = []): array
    {
        return group(static function (Group $group) use ($zone, $host, $search, $hosts) {
            $dns = FakeDns::start($group, $zone);
            $resolver = new DnsResolver(
                nameservers: [$dns->address],
                hosts: $hosts,
                search: $search,
                timeout: 1,
                attempts: 1,
            );

            return [$resolver->resolve($host), $dns->queries];
        });
    }
}

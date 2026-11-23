<?php

declare(strict_types=1);

namespace Thesis\Net;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\virtualTime;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

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
            zone: ['db' => self::a('10.0.0.9'), 'db.cluster.test' => self::a('10.0.0.1')],
            host: 'db',
            search: ['svc.test', 'cluster.test'],
        );

        Assert::same($addresses, ['10.0.0.1']);
        Assert::same($queries, [
            'udp db.svc.test AAAA',
            'udp db.svc.test A',
            'udp db.cluster.test AAAA',
            'udp db.cluster.test A',
        ]);
    }

    public function triesTheNameAsIsFirstWithEnoughDots(): void
    {
        [$addresses, $queries] = self::resolve(
            zone: ['db.test.cluster.test' => self::a('10.0.0.1')],
            host: 'db.test',
            search: ['svc.test', 'cluster.test'],
        );

        Assert::same($addresses, ['10.0.0.1']);
        Assert::same($queries, [
            'udp db.test AAAA',
            'udp db.test A',
            'udp db.test.svc.test AAAA',
            'udp db.test.svc.test A',
            'udp db.test.cluster.test AAAA',
            'udp db.test.cluster.test A',
        ]);
    }

    public function skipsTheSearchDomainsForAnAbsoluteName(): void
    {
        [, $queries] = self::resolve(['db' => self::a('10.0.0.1')], 'db.', search: ['svc.test']);

        Assert::same($queries, ['udp db AAAA', 'udp db A']);
    }

    public function cachesAnAbsoluteNameApartFromTheSameNameWithSearchDomains(): void
    {
        $zone = ['db' => self::a('10.0.0.9'), 'db.cluster.test' => self::a('10.0.0.1')];

        $addresses = group(static function (Group $group) use ($zone) {
            $dns = FakeDns::start($group, $zone);
            $resolver = new DnsResolver(nameservers: [$dns->address], search: ['cluster.test']);

            return [$resolver->resolve('db'), $resolver->resolve('db.'), $resolver->resolve('db')];
        });

        Assert::same($addresses, [['10.0.0.1'], ['10.0.0.9'], ['10.0.0.1']]);
    }

    public function retriesOverTcpIfTruncated(): void
    {
        $zone = new Zone(a: ['10.0.0.1'], truncated: true);

        [$addresses, $queries] = self::resolve(['big.test' => $zone], 'big.test');

        Assert::same($addresses, ['10.0.0.1']);
        Assert::same(\array_slice($queries, 0, 2), ['udp big.test AAAA', 'udp big.test A']);
        // Both families connect over TCP at once, and the kernel decides whose connection is ready first.
        $tcp = \array_slice($queries, 2);
        sort($tcp);
        Assert::same($tcp, ['tcp big.test A', 'tcp big.test AAAA']);
    }

    public function readsATcpAnswerThatComesInPieces(): void
    {
        $zone = new Zone(a: ['10.0.0.1'], truncated: true, byteByByte: true);

        [$addresses] = self::resolve(['big.test' => $zone], 'big.test');

        Assert::same($addresses, ['10.0.0.1']);
    }

    public function rejectsATcpAnswerToAnotherQuery(): void
    {
        Expect::exception(DnsFailed::class)->withMessageContaining('The answer is to another query.');

        self::resolve(['big.test' => new Zone(a: ['10.0.0.1'], truncated: true, anotherId: true)], 'big.test');
    }

    public function asksTheNextServerIfOneFailsOrIsSilent(): void
    {
        // bounded: a silent server is given the timeout of the resolver, not the default one
        $addresses = timeout(sec(1), static fn() => group(static function (Group $group) {
            $failing = FakeDns::start($group, ['db.test' => new Zone(rcode: 2)]);
            $silent = FakeDns::start($group, ['db.test' => new Zone(silent: true)]);
            $working = FakeDns::start($group, ['db.test' => self::a('10.0.0.1')]);
            $resolver = new DnsResolver(
                nameservers: [$failing->address, $silent->address, $working->address],
                timeout: ms(50),
            );

            return $resolver->resolve('db.test');
        }));

        Assert::same($addresses, ['10.0.0.1']);
    }

    public function asksTheNextServerIfOneRefuses(): void
    {
        $addresses = group(static function (Group $group) {
            $working = FakeDns::start($group, ['db.test' => self::a('10.0.0.1')]);
            $resolver = new DnsResolver(nameservers: [self::closedPort(), $working->address], attempts: 1);

            return $resolver->resolve('db.test');
        });

        Assert::same($addresses, ['10.0.0.1']);
    }

    public function closesTheSocketsOfItsQueries(): void
    {
        // A socket left open is freed by the cycle collector some time later: the test must not depend on when.
        gc_disable();

        try {
            $before = \count(get_resources('stream'));

            self::resolve(['big.test' => new Zone(a: ['10.0.0.1'], truncated: true)], 'big.test');
            // cancelled while it waits for the answer over UDP, then over TCP
            timeoutOr(ms(100), static fn() => self::resolve(['db.test' => new Zone(silent: true)], 'db.test'));
            group(static function (Group $group) {
                $dns = FakeDns::start($group, ['big.test' => new Zone(a: ['10.0.0.1'], truncated: true, byteByByte: true)]);
                $resolving = $group->spawn(static fn() => new DnsResolver([$dns->address])->resolve('big.test'));

                // the answer over TCP comes a byte at a time: once asked, the query is reading it
                while (!\in_array('tcp big.test A', $dns->queries, strict: true)) {
                    delay(ms(1));
                }

                $resolving->cancel();
            });

            Assert::same(\count(get_resources('stream')), $before);
        } finally {
            gc_enable();
        }
    }

    #[DataSet([1, 'format error'])]
    #[DataSet([2, 'server failure'])]
    #[DataSet([4, 'not implemented'])]
    #[DataSet([5, 'refused'])]
    #[DataSet([9, 'response code 9'])]
    public function reportsWhatTheServersSaidOnceForEveryAttempt(int $rcode, string $said): void
    {
        [$message, $queries] = group(static function (Group $group) use ($rcode) {
            $failing = FakeDns::start($group, ['db.test' => new Zone(rcode: $rcode)]);

            try {
                new DnsResolver([$failing->address], attempts: 2)->resolve('db.test');
                $message = null;
            } catch (DnsFailed $exception) {
                // the port is random
                $message = str_replace((string) $failing->address->port, '%d', $exception->getMessage());
            }

            return [$message, $failing->queries];
        });

        Assert::same($message, "Failed to resolve \"db.test\": db.test: 127.0.0.1:%d: {$said}");
        Assert::same(\count($queries), 4);
    }

    public function anAnswerCanExpireWhileWaitingForTheOtherFamily(): void
    {
        virtualTime(static function () {
            timeout(sec(10), static fn() => group(static function (Group $group) {
                $zone = new Zone(aaaa: ['fd00::1'], ttl: 1, silentA: true);
                $dns = FakeDns::start($group, ['db.test' => $zone]);
                $resolver = new DnsResolver([$dns->address], timeout: sec(2), attempts: 1);
                Assert::same($resolver->resolve('db.test'), ['fd00::1']);
                $dns->queries = [];

                Assert::same($resolver->resolve('db.test'), ['fd00::1']);
                Assert::contains($dns->queries, 'udp db.test AAAA');
            }));
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
            delay(ms(1010));
            $resolver->resolve('db.test');

            return \count($dns->queries);
        });

        Assert::same($queries, 4);
    }

    public function evictsTheOldestOfAFullCache(): void
    {
        $queries = self::queriesAfterFillingTheCache(new Zone(a: ['10.0.0.1']));

        Assert::same($queries, ['udp host0.test AAAA', 'udp host0.test A', 'udp host1.test AAAA', 'udp host1.test A']);
    }

    public function evictsNothingForAZeroTtl(): void
    {
        $queries = self::queriesAfterFillingTheCache(new Zone(a: ['10.0.0.1'], ttl: 0));

        Assert::same($queries, ['udp host0.test AAAA', 'udp host0.test A', 'udp host0.test AAAA', 'udp host0.test A']);
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
     * Resolves host1.test to host1024.test into a cache that holds 1024, then host0.test of $next twice,
     * then host2.test and host1.test, the oldest.
     *
     * @return list<string> the queries after the cache is full
     */
    private static function queriesAfterFillingTheCache(Zone $next): array
    {
        return group(static function (Group $group) use ($next) {
            $zone = ['host0.test' => $next];

            for ($i = 1; $i <= 1024; ++$i) {
                $zone["host{$i}.test"] = self::a('10.0.0.1');
            }

            $dns = FakeDns::start($group, $zone);
            $resolver = new DnsResolver([$dns->address]);

            for ($i = 1; $i <= 1024; ++$i) {
                $resolver->resolve("host{$i}.test");
            }

            $full = \count($dns->queries);
            $resolver->resolve('host0.test');
            $resolver->resolve('host0.test');
            $resolver->resolve('host2.test');
            $resolver->resolve('host1.test');

            return \array_slice($dns->queries, $full);
        });
    }

    /**
     * This helper returns a UDP port with no current listener. The port refuses queries.
     */
    private static function closedPort(): InetAddress
    {
        $socket = stream_socket_server('udp://127.0.0.1:0', flags: STREAM_SERVER_BIND);
        \assert($socket !== false);
        $address = stream_socket_get_name($socket, remote: false);
        fclose($socket);
        \assert(\is_string($address));
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        \assert($port > 0 && $port <= 65535);

        return new InetAddress(ip: '127.0.0.1', port: $port);
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
                timeout: sec(1),
                attempts: 1,
            );

            return [$resolver->resolve($host), $dns->queries];
        });
    }
}

<?php

declare(strict_types=1);

namespace Thesis\Net\Internal\Dns;

use Testo\Assert;
use Testo\Test;

#[Test]
final class SystemConfigTest
{
    public function parsesResolvConf(): void
    {
        $config = SystemConfig::parse(
            resolvConf: <<<'CONF'
                # comment
                nameserver 10.0.0.1
                nameserver ::1 ; comment
                nameserver not-an-ip
                nameserver 10.0.0.2
                nameserver 10.0.0.3
                domain ignored.test
                search svc.test. cluster.test
                options ndots:5 timeout:2 attempts:3 rotate
                CONF,
            hosts: '',
        );

        Assert::same(array_map(strval(...), $config->nameservers), ['10.0.0.1:53', '[::1]:53', '10.0.0.2:53']);
        Assert::same($config->search, ['svc.test', 'cluster.test']);
        Assert::same($config->ndots, 5);
        Assert::same($config->timeout, 2);
        Assert::same($config->attempts, 3);
    }

    public function defaultsToTheLocalServer(): void
    {
        $config = SystemConfig::parse(resolvConf: '', hosts: '');

        Assert::same(array_map(strval(...), $config->nameservers), ['127.0.0.1:53']);
        Assert::same($config->search, []);
        Assert::same($config->ndots, 1);
        Assert::same($config->timeout, 5);
        Assert::same($config->attempts, 2);
    }

    public function parsesHosts(): void
    {
        $config = SystemConfig::parse(
            resolvConf: '',
            hosts: <<<'HOSTS'
                127.0.0.1	localhost
                ::1	localhost ip6-localhost
                10.0.0.5 DB db.local # primary
                # 10.0.0.6 commented
                garbage line
                HOSTS,
        );

        Assert::same($config->hosts, [
            'localhost' => ['127.0.0.1', '::1'],
            'ip6-localhost' => ['::1'],
            'db' => ['10.0.0.5'],
            'db.local' => ['10.0.0.5'],
        ]);
    }

    public function loadsMissingFilesAsEmpty(): void
    {
        $config = SystemConfig::load('/nonexistent/resolv.conf', '/nonexistent/hosts');

        Assert::same($config->hosts, []);
    }
}

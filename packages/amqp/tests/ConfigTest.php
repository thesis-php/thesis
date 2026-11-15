<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class ConfigTest
{
    public function takesTheDefaultsOfRabbitMqForAShortUri(): void
    {
        $config = Config::fromUri('amqp://');

        Assert::same($config->hosts, ['localhost']);
        Assert::same($config->user, 'guest');
        Assert::same($config->password, 'guest');
        Assert::same($config->vhost, '/');
        Assert::null($config->tls);
        Assert::equals($config->heartbeat, sec(60));
        Assert::equals($config->connectTimeout, sec(30));
    }

    public function parsesEveryPart(): void
    {
        $config = Config::fromUri(
            'amqp://us%40er:p%3Ass@rabbit-1:5673,rabbit-2,[::1]:5674/my%2Fvhost'
            . '?heartbeat=10&connection_timeout=2500&channel_max=100&frame_max=8192'
            . '&auth_mechanism=external&auth_mechanism=plain&connection_name=worker',
        );

        Assert::same($config->hosts, ['rabbit-1:5673', 'rabbit-2', '[::1]:5674']);
        Assert::same($config->user, 'us@er');
        Assert::same($config->password, 'p:ss');
        Assert::same($config->vhost, 'my/vhost');
        Assert::equals($config->heartbeat, sec(10));
        Assert::equals($config->connectTimeout, ms(2500));
        Assert::same($config->channelMax, 100);
        Assert::same($config->frameMax, 8192);
        Assert::same($config->authMechanisms, [AuthMechanism::External, AuthMechanism::Plain]);
        Assert::same($config->connectionName, 'worker');
    }

    public function decodesTheVhostOfOneSlash(): void
    {
        Assert::same(Config::fromUri('amqp://localhost/%2F')->vhost, '/');
        Assert::same(Config::fromUri('amqp://localhost/')->vhost, '/');
    }

    public function turnsOnTlsForAmqps(): void
    {
        $config = Config::fromUri(
            'amqps://localhost?cacertfile=/ca.pem&certfile=/cert.pem&keyfile=/key.pem&server_name_indication=rabbit',
        );

        Assert::notNull($config->tls);
        Assert::same($config->tls->peerName, 'rabbit');
        Assert::same($config->tls->caFile, '/ca.pem');
        Assert::true($config->tls->verifyPeer);
        Assert::same($config->tls->certificate?->certFile, '/cert.pem');
    }

    public function skipsVerificationWhenAsked(): void
    {
        $tls = Config::fromUri('amqps://localhost?verify=verify_none')->tls;

        Assert::notNull($tls);
        Assert::false($tls->verifyPeer);
        Assert::false($tls->verifyPeerName);
    }

    public function rejectsAnotherScheme(): void
    {
        Expect
            ::exception(\InvalidArgumentException::class)
            ->withMessage('The URI must start with amqp:// or amqps://.')
        ;

        Config::fromUri('http://localhost');
    }

    public function rejectsANumberThatIsNot(): void
    {
        Expect
            ::exception(\InvalidArgumentException::class)
            ->withMessage('The heartbeat of the URI must be a non-negative integer, got "-1".')
        ;

        Config::fromUri('amqp://localhost?heartbeat=-1');
    }

    public function rejectsAMalformedHost(): void
    {
        Expect
            ::exception(\InvalidArgumentException::class)
            ->withMessage('The host "a:b:c" is malformed: use host, host:port or [ipv6]:port.')
        ;

        new Config(hosts: ['localhost', 'a:b:c']);
    }

    public function rejectsAPortAbove65535(): void
    {
        Expect
            ::exception(\InvalidArgumentException::class)
            ->withMessage('The port of the host "localhost:70000" is greater than 65535.')
        ;

        new Config(hosts: ['localhost:70000']);
    }

    public function rejectsAnUnknownMechanism(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Unknown auth mechanism "magic".');

        Config::fromUri('amqp://localhost?auth_mechanism=magic');
    }
}

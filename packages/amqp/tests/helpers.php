<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Async\Group;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeout;
use function Thesis\Time\sec;

/**
 * Runs a client of $config in $group against the next connection to $server, after a handshake.
 *
 * @return array{Client, FakePeer}
 */
function connect(Group $group, FakeServer $server, ?Config $config = null): array
{
    $peer = $group->spawn(static function () use ($server) {
        $peer = $server->accept();
        $peer->handshake();

        return $peer;
    });
    $client = new Client($config ?? $server->config());
    $client->run($group);

    return [$client, $peer->await()];
}

/**
 * Opens a channel of $client that $peer answers.
 *
 * @return array{Channel, int<1, 65535>}
 */
function openChannel(Group $group, Client $client, FakePeer $peer): array
{
    $id = $group->spawn($peer->openChannel(...));
    $channel = $client->channel();

    return [$channel, $id->await()];
}

/**
 * What $fn throws, for a task whose failure the test awaits: a task that throws fails its group.
 *
 * @param callable(): mixed $fn
 */
function failure(callable $fn): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * Closes $client gracefully, $peer agreeing.
 */
function closeClient(Group $group, Client $client, FakePeer $peer): void
{
    $group->spawn($peer->acceptClose(...));
    $client->close();
}

/**
 * Runs $test with a client of the RabbitMQ of compose.yaml (`make up`), closed afterwards.
 *
 * @param callable(Client, Group): void $test
 */
function withRabbitMq(callable $test): void
{
    $uri = getenv('AMQP_URI');
    $config = Config::fromUri(\is_string($uri) && $uri !== '' ? $uri : 'amqp://thesis:thesis@localhost:5672/');
    $client = new Client($config);

    group(static function (Group $group) use ($client, $test) {
        $client->run($group);

        try {
            $test($client, $group);
        } finally {
            shield(static fn() => timeout(sec(5), $client->close(...)));
        }
    });
}

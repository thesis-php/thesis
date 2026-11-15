<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Async\Group;

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
 * Closes $client gracefully, $peer agreeing.
 */
function closeClient(Group $group, Client $client, FakePeer $peer): void
{
    $group->spawn($peer->acceptClose(...));
    $client->close();
}

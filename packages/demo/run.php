<?php

declare(strict_types=1);

use Thesis\Async\Group;
use Thesis\Demo\Internal\DelayedResponse;
use Thesis\Http\Server;
use Thesis\Http\Server\Config;
use Thesis\Logger;
use Thesis\Logger\Formatter\Console;
use function Thesis\Async\awaitSignal;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeout;
use function Thesis\Io\stderr;
use function Thesis\Time\sec;

$autoload = __DIR__ . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : dirname(__DIR__, 2) . '/vendor/autoload.php';

$endpoint = $argv[1] ?? '127.0.0.1:8080';
if ($endpoint === '') {
    throw new InvalidArgumentException('The listening address must not be empty.');
}

$logger = new Logger(stderr(), formatter: new Console());
$server = new Server(config: new Config($endpoint), handler: new DelayedResponse($logger));

group(static function (Group $group) use ($logger, $server) {
    $logger->start($group);

    try {
        $server->start($group);
        $logger->info('Listening on {address}', ['address' => (string) $server->address]);
        awaitSignal(SIGINT, SIGTERM);
    } finally {
        try {
            shield($server->stop(...));
        } finally {
            shield(static fn() => timeout(sec(2), $logger->stop(...)));
        }
    }
});

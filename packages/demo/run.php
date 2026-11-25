<?php

declare(strict_types=1);

use Thesis\Async\Group;
use Thesis\Dispatcher;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Route;
use Thesis\Http\Server\Router;
use Thesis\Http\Server\ServerLogger;
use Thesis\Logger;
use Thesis\Logger\Formatter\Console;
use function Thesis\Async\awaitSignal;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeout;
use function Thesis\Http\Server\routeMatch;
use function Thesis\Io\stderr;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

$autoload = __DIR__ . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : dirname(__DIR__, 2) . '/vendor/autoload.php';

group(static function (Group $group) {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];
    $endpoint = $arguments[1] ?? '127.0.0.1:8080';
    if ($endpoint === '') {
        throw new InvalidArgumentException('The listening address must not be empty.');
    }

    $logger = new Logger(stderr(), formatter: new Console());
    $server = new Server(
        config: new Config($endpoint),
        handler: new Router([
            new Route(Method::GET, '/', static fn() => new Response(body: "Try GET /1 for a delayed response.\n")),
            new Route(Method::GET, '/:id', static function (Request $request) {
                delay(ms(250));

                $id = routeMatch($request)
                    ?->param('id')
                    ?? throw new LogicException('Expected the /:id route to supply id.')
                ;

                return new Response(body: $id);
            }),
        ]),
        dispatcher: new Dispatcher([
            new ServerLogger($logger, accessLog: true),
        ]),
    );

    $logger->start($group);

    try {
        $server->start($group);
        awaitSignal(SIGINT, SIGTERM);
    } finally {
        try {
            shield($server->stop(...));
        } finally {
            shield(static fn() => timeout(sec(2), $logger->stop(...)));
        }
    }
});

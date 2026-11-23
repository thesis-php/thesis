<?php

declare(strict_types=1);

use Thesis\Async\Group;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\HttpServer\Config;
use Thesis\HttpServer\Server;
use function Thesis\Async\awaitSignal;
use function Thesis\Async\group;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$server = new Server(
    config: new Config('0.0.0.0:8080'),
    handler: static fn(Request $request) => new Response(body: $request->body),
);

group(static function (Group $group) use ($server) {
    $server->run($group);
    awaitSignal(SIGINT, SIGTERM);
    $server->close();
});

<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'A small HTTP server with console logging that shows how concurrent requests overlap.',
    licenseSince: 2026,
    version: '0.1',
    keywords: ['php', 'async', 'demo', 'http', 'http-server', 'logging'],
    require: [
        'ext-pcntl' => '*',
        'thesis/async' => '*',
        'thesis/http' => '*',
        'thesis/io' => '*',
        'thesis/logger' => '*',
        'thesis/time' => '*',
    ],
    dist: ['run.php'],
    type: 'project',
    devMain: true,
);

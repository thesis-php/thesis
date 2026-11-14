<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\StringableForToStringRector;

$root = dirname(__DIR__);

return RectorConfig::configure()
    ->withPaths([
        $root . '/bin/monorepo',
        $root . '/monorepo.php',
        $root . '/tools',
        $root . '/packages',
    ])
    ->withParallel()
    ->withCache($root . '/var/rector')
    ->withPhpSets()
    ->withSkip([
        StringableForToStringRector::class,
    ]);

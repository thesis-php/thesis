<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use SavinMikhail\AddNamedArgumentsRector\AddNamedArgumentsRector;
use Thesis\Rector\MoreThanTwoArgumentsStrategy;

require_once __DIR__ . '/rector/MoreThanTwoArgumentsStrategy.php';

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
    ->withConfiguredRule(AddNamedArgumentsRector::class, [
        AddNamedArgumentsRector::STRATEGY => MoreThanTwoArgumentsStrategy::class,
        AddNamedArgumentsRector::ALLOW_NAMED_VARIADIC_ARGUMENTS => false,
    ])
    ->withSkip([
        StringableForToStringRector::class,
    ]);

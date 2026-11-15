<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\Class_\StringableForToStringRector;

$root = dirname(__DIR__);

return RectorConfig
    ::configure()
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
        // The property is non-negative-int, the parameter is positive-int: promotion would lose the property's type.
        ClassPropertyAssignToConstructorPromotionRector::class => [
            $root . '/packages/async/src/LocalSemaphore.php',
        ],
    ])
;

<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Attribute\SortAttributeNamedArgsRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\Tools;

return RectorConfig
    ::configure()
    ->withPaths([
        __DIR__ . '/bin/monorepo',
        __DIR__ . '/tools',
        __DIR__ . '/packages',
    ])
    ->withRootFiles()
    ->withParallel()
    ->withCache(__DIR__ . '/var/rector')
    ->withPhpSets()
    ->withRules([
        SortCallLikeNamedArgsRector::class,
        SortAttributeNamedArgsRector::class,
    ])
    ->withSkip([
        StringableForToStringRector::class,
        // The fixtures call functions with named arguments out of order on purpose.
        SortCallLikeNamedArgsRector::class => [__DIR__ . '/packages/phpstan-extension/types'],
        ...Tools::of(Monorepo::load(__DIR__))->rectorSkips,
    ])
;

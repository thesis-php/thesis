<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Attribute\SortAttributeNamedArgsRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\ToolSkips;

return RectorConfig
    ::configure()
    ->withPaths([
        __DIR__ . '/bin/monorepo',
        __DIR__ . '/.php-cs-fixer.php',
        __DIR__ . '/composer-dependency-analyser.php',
        __DIR__ . '/monorepo.php',
        __DIR__ . '/rector.php',
        __DIR__ . '/tally.php',
        __DIR__ . '/tools',
        __DIR__ . '/packages',
    ])
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
        ...ToolSkips::of(Monorepo::load(__DIR__))->rector,
    ])
;

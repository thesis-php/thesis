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
    // The container cache goes to var/cache: in /tmp of the Docker container (the default) it is rebuilt on every run
    // and cannot be written once the disk of the Docker VM is full. Not a directory of its own: Rector requires
    // the directory to exist, and `make` creates only var.
    ->withCache(cacheDirectory: __DIR__ . '/var/rector', containerCacheDirectory: __DIR__ . '/var')
    // Not up to the PHP of composer.json: the syntax of PHP 8.6 waits until every analyzer parses it (agents/php.md).
    ->withPhpSets(php85: true)
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
